<?php

namespace Tests\Feature\Reports;

use App\Enums\DashboardAlert;
use App\Enums\DispensationStatus;
use App\Enums\FeePaymentMethod;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Reports\DiscountsReportPage;
use App\Models\Dispensation;
use App\Models\DispensationLine;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipFeePayment;
use App\Models\MembershipTier;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Money;
use App\Support\Period;
use App\Support\Settings;
use App\Support\Spreadsheet\ReportExport;
use App\ViewModels\Reports\ConsumptionReport;
use App\ViewModels\Reports\DiscountsReport;
use App\ViewModels\Reports\FinancialReport;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 291 — "Say the staff have been giving loads of discounts — where can the owner monitor this?" Four ways value
 * leaves: member discounts (automatic), price overrides, waived fees, and manual bar lines (money TAKEN, where
 * undercharging hides). One report puts them together, per operator; one alert watches the discretionary share.
 */
class DiscountsReportTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private Location $norte;

    private User $ana;

    private User $bruno;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        $this->norte = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte']);
        $this->ana = $this->person(Role::STAFF, 'Ana Barra', [$this->centro]);
        $this->bruno = $this->person(Role::MANAGER, 'Bruno Encargado', [$this->centro]);
        $this->owner = $this->person(Role::OWNER, 'Olga Dueña', [$this->centro, $this->norte]);
        $this->travelTo(now()->setTime(12, 0));
    }

    /** @param list<Location> $sedes */
    private function person(Role $role, string $name, array $sedes): User
    {
        $u = User::factory()->create(['name' => $name]);
        $u->assignRole($role->value);
        $u->locations()->sync(array_map(fn (Location $l): string => $l->id, $sedes));

        return $u;
    }

    private function member(): Member
    {
        return Member::factory()->create(['organisation_id' => $this->org->id]);
    }

    /** @param list<array{discount: int, note: ?string, total: int}> $lines */
    private function dispensation(User $by, int $total, array $lines = [], ?int $original = null, ?User $overrideBy = null, bool $voided = false, ?Location $at = null): Dispensation
    {
        $d = Dispensation::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => ($at ?? $this->centro)->id, 'member_id' => $this->member()->id,
            'operator_id' => $by->id, 'total_cents' => $total, 'cash_cents' => $total, 'wallet_cents' => 0,
            'original_total_cents' => $original, 'price_override_by' => $overrideBy?->id,
            'price_override_reason' => $original !== null ? 'Cliente habitual' : null,
            'status' => $voided ? DispensationStatus::VOIDED : DispensationStatus::COMPLETED, 'dispensed_at' => now(),
        ]);
        foreach ($lines as $line) {
            DispensationLine::factory()->create([
                'dispensation_id' => $d->id, 'discount_cents' => $line['discount'], 'pricing_note' => $line['note'],
                'line_total_cents' => $line['total'],
            ]);
        }

        return $d;
    }

    /** @param list<array<string, mixed>> $items */
    private function order(User $by, array $items, bool $voided = false): Order
    {
        $total = array_sum(array_column($items, 'line_total_cents'));

        return Order::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->centro->id, 'operator_id' => $by->id,
            'items' => $items, 'total_cents' => $total, 'cash_cents' => $total, 'wallet_cents' => 0,
            'status' => $voided ? OrderStatus::VOIDED : OrderStatus::COMPLETED,
        ]);
    }

    private function waiver(User $by, int $amount): void
    {
        $membership = Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $this->member()->id, 'location_id' => $this->centro->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
        ]);
        MembershipFeePayment::factory()->create([
            'membership_id' => $membership->id, 'amount_cents' => $amount, 'method' => FeePaymentMethod::WAIVED,
            'reason' => 'Socio fundador', 'recorded_by' => $by->id, 'paid_at' => now(),
        ]);
    }

    /** The fixture of test 2, hand-computed below. */
    private function fixture(): void
    {
        $this->dispensation($this->ana, 2000, [['discount' => 300, 'note' => 'Tarifa · Terapéutico −15.00%', 'total' => 2000]]);
        $this->dispensation($this->bruno, 1500, [['discount' => 0, 'note' => null, 'total' => 1500]], original: 2000, overrideBy: $this->bruno);
        $this->dispensation($this->ana, 1800, [['discount' => 200, 'note' => 'Tarifa · Personal −10.00%', 'total' => 1800]]);
        $this->dispensation($this->ana, 5000, [['discount' => 1000, 'note' => 'Tarifa · Personal −10.00%', 'total' => 5000]], original: 6000, overrideBy: $this->ana, voided: true);
        $this->order($this->ana, [
            ['article_id' => 'a1', 'name' => 'Agua', 'unit_price_cents' => 550, 'qty' => 1, 'discount_cents' => 50, 'line_total_cents' => 500, 'reference' => null],
            ['article_id' => null, 'name' => 'Varios', 'unit_price_cents' => 200, 'qty' => 1, 'line_total_cents' => 200, 'reference' => 'Mechero'],
        ]);
        $this->order($this->bruno, [['article_id' => null, 'name' => 'Varios', 'unit_price_cents' => 300, 'qty' => 1, 'line_total_cents' => 300, 'reference' => 'Papel']]);
        $this->order($this->bruno, [['article_id' => null, 'name' => 'Varios', 'unit_price_cents' => 999, 'qty' => 1, 'line_total_cents' => 999, 'reference' => 'Anulado']], voided: true);
        $this->waiver($this->ana, 2000);
    }

    private function report(?array $locationIds = null, ?Period $period = null): DiscountsReport
    {
        return new DiscountsReport($this->org->id, $locationIds ?? [$this->centro->id], $period ?? Period::today());
    }

    /** @return array<string, string> label => value */
    private function cards(DiscountsReport $report): array
    {
        return collect($report->summary())->mapWithKeys(fn (array $c): array => [$c['key'] => $c['value']])->all();
    }

    // 1 -------------------------------------------------------------------------------------------------------------

    public function test_the_existing_override_and_waiver_figures_are_unchanged(): void
    {
        $this->dispensation($this->bruno, 1500, [['discount' => 0, 'note' => null, 'total' => 1500]], original: 2000, overrideBy: $this->bruno);
        $this->dispensation($this->ana, 900, [['discount' => 0, 'note' => null, 'total' => 900]], original: 1000, overrideBy: $this->bruno);
        $this->waiver($this->ana, 2000);
        $this->waiver($this->bruno, 3500);

        $consumption = collect((new ConsumptionReport($this->org->id, [$this->centro->id], Period::today()))->summary())->firstWhere('label', __('Ajustes de precio'));
        $this->assertSame(Money::fromCents(600)->formatted(), $consumption['value']);

        $waived = collect((new FinancialReport($this->org->id, [$this->centro->id], Period::today()))->tables())->firstWhere('key', 'waived');
        $this->assertSame(5500, $waived->totals['importe']);
        $this->assertCount(2, $waived->rows);
    }

    // 2, 3, 4 -------------------------------------------------------------------------------------------------------

    public function test_the_cards_match_the_hand_computed_fixture_voids_excluded(): void
    {
        $this->fixture();
        $cards = $this->cards($this->report());

        $this->assertSame(Money::fromCents(550)->formatted(), $cards['member_discounts']);  // 300 + 200 + 50 (bar)
        $this->assertSame(Money::fromCents(500)->formatted(), $cards['overrides']);         // Bruno's one, the voided one excluded
        $this->assertSame(Money::fromCents(2000)->formatted(), $cards['waivers']);
        $this->assertStringStartsWith('2 ', $cards['manual_lines']);                          // 2 lines…
        $this->assertStringContainsString(Money::fromCents(500)->formatted(), $cards['manual_lines']); // …worth €5, the voided one excluded
        // Total cedido = 550 + 500 + 2000 = 3050 — manual lines NOT included — of 6300 takings = 48 %.
        $this->assertSame(Money::fromCents(3050)->formatted().' · 48 %', $cards['total_given']);
    }

    // 5 -------------------------------------------------------------------------------------------------------------

    public function test_each_operator_gets_their_own_acts_and_member_discounts_are_information_only(): void
    {
        $this->fixture();
        $rows = collect(collect($this->report()->tables())->firstWhere('key', 'by_operator')->rows)->keyBy('operador');

        $ana = $rows['Ana Barra'];
        $this->assertSame(3, $ana['ventas']);
        $this->assertSame(4500, $ana['recaudado']);
        $this->assertSame(0, $ana['ajustes_importe']);
        $this->assertSame(2000, $ana['condonaciones_importe']);
        $this->assertSame(1, $ana['manuales']);
        $this->assertSame(200, $ana['manuales_importe']);
        $this->assertSame(2000, $ana['discrecional']);
        $this->assertSame(44, $ana['discrecional_pct']);
        $this->assertSame(550, $ana['descuentos_socio']);

        $bruno = $rows['Bruno Encargado'];
        $this->assertSame(2, $bruno['ventas']);
        $this->assertSame(1800, $bruno['recaudado']);
        $this->assertSame(500, $bruno['ajustes_importe']);
        $this->assertSame(28, $bruno['discrecional_pct']);
        $this->assertSame(0, $bruno['descuentos_socio']);
    }

    // 6 -------------------------------------------------------------------------------------------------------------

    public function test_member_discounts_group_by_their_label(): void
    {
        $this->fixture();
        $rows = collect(collect($this->report()->tables())->firstWhere('key', 'by_type')->rows)->keyBy('tipo');

        $this->assertSame(300, $rows['Tarifa · Terapéutico −15.00%']['importe']);
        $this->assertSame(200, $rows['Tarifa · Personal −10.00%']['importe']); // the voided sale's 1000 is not here
        $this->assertSame(50, $rows[__('Barra y tienda: descuento de socio')]['importe']);
    }

    // 7 -------------------------------------------------------------------------------------------------------------

    public function test_the_alert_fires_over_the_threshold_only_with_enough_takings_over_seven_days(): void
    {
        Settings::set('discount_alert_threshold_pct', 10, SettingType::INT);

        // Bruno: €60 of takings, a €10 override → 16 % (> 10 %) with takings ≥ €50 → fires.
        $this->dispensation($this->bruno, 5000, [], original: 6000, overrideBy: $this->bruno);
        $this->dispensation($this->bruno, 1000);
        // Ana: €20 of takings, a €10 waiver → 50 %, but under €50 → does not fire.
        $this->dispensation($this->ana, 2000);
        $this->waiver($this->ana, 1000);
        // Ten days ago: outside the 7-day window whatever the dashboard period.
        $this->travel(-10)->days();
        $this->dispensation($this->ana, 10000, [], original: 20000, overrideBy: $this->ana);
        $this->travelBack();
        $this->travelTo(now()->setTime(12, 0));

        $this->assertSame(1, DiscountsReport::operatorsAboveThreshold([$this->centro->id]));
    }

    // 8 -------------------------------------------------------------------------------------------------------------

    public function test_managers_see_their_sedes_and_staff_are_refused(): void
    {
        $this->fixture();
        $this->dispensation($this->owner, 4000, [['discount' => 999, 'note' => 'Norte −5%', 'total' => 4000]], at: $this->norte);

        $html = Livewire::actingAs($this->bruno)->test(DiscountsReportPage::class)->assertOk()->html();
        $this->assertStringNotContainsString('Norte −5%', $html);
        $this->assertStringContainsString('Ana Barra', $html);

        Livewire::actingAs($this->ana)->test(DiscountsReportPage::class)->assertForbidden();
    }

    // 9 -------------------------------------------------------------------------------------------------------------

    public function test_the_csv_matches_the_per_operator_table(): void
    {
        $this->fixture();
        $report = $this->report();
        $table = collect($report->tables())->firstWhere('key', 'by_operator');

        $csv = ReportExport::csv($table);
        foreach ($table->rows as $row) {
            $this->assertStringContainsString($row['operador'], $csv);
        }
        $this->assertMatchesRegularExpression('/45[.,]00/', $csv); // Ana's takings, in the locale's decimal
    }

    // 10 ------------------------------------------------------------------------------------------------------------

    public function test_a_departed_operator_keeps_their_name_marked_as_left(): void
    {
        $this->fixture();
        $this->ana->delete();

        $rows = collect(collect($this->report()->tables())->firstWhere('key', 'by_operator')->rows)->pluck('operador')->all();
        $this->assertContains('Ana Barra ('.__('ya no está').')', $rows);
    }

    // 11 ------------------------------------------------------------------------------------------------------------

    public function test_the_report_runs_bounded_queries_whatever_the_number_of_sales(): void
    {
        $this->fixture();
        DB::enableQueryLog();
        $this->report()->tables();
        $this->report()->summary();
        $few = count(DB::getQueryLog());
        DB::disableQueryLog();

        foreach (range(1, 25) as $i) {
            $this->dispensation($i % 2 ? $this->ana : $this->bruno, 1000, [['discount' => 50, 'note' => 'Personal', 'total' => 1000]]);
            $this->order($this->ana, [['article_id' => null, 'name' => 'Varios', 'unit_price_cents' => 100, 'qty' => 1, 'line_total_cents' => 100, 'reference' => 'x']]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->report()->tables();
        $this->report()->summary();
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($few, $many, "the report's query count must not grow with the number of sales");
    }

    public function test_the_dashboard_alert_shows_for_reports_viewers_only_and_links_to_the_seven_days(): void
    {
        Settings::set('discount_alert_threshold_pct', 1, SettingType::INT);
        $this->dispensation($this->bruno, 5000, [], original: 6000, overrideBy: $this->bruno);
        app(ActiveScope::class)->setLocation($this->centro->id);

        $html = Livewire::actingAs($this->owner)->test(Dashboard::class)->html();
        $this->assertStringContainsString(e(DashboardAlert::DISCOUNTS_ABOVE_THRESHOLD->label(1)), $html);
        $this->assertStringContainsString('informes/descuentos?days=7', $html);

        $staff = Livewire::actingAs($this->ana)->test(Dashboard::class)->html();
        $this->assertStringNotContainsString(e(DashboardAlert::DISCOUNTS_ABOVE_THRESHOLD->label(1)), $staff);
    }
}
