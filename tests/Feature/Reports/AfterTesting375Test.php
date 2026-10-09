<?php

namespace Tests\Feature\Reports;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Members\AssignMemberDiscount;
use App\Actions\Memberships\EnrolMembership;
use App\Actions\Stock\RecordStockMovement;
use App\Enums\AlertType;
use App\Enums\BatchStatus;
use App\Enums\DashboardAlert;
use App\Enums\DiscountAppliesTo;
use App\Enums\DiscountKind;
use App\Enums\DiscountMode;
use App\Enums\DispensationStatus;
use App\Enums\MemberStatus;
use App\Enums\ProductType;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Enums\StockMovementType;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Reports\ConsumptionReportPage;
use App\Filament\Pages\Reports\DiscountsReportPage;
use App\Filament\Pages\Reports\LossesReportPage;
use App\Models\Batch;
use App\Models\Discount;
use App\Models\Dispensation;
use App\Models\DispensationLine;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\OwnerAlertState;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Alerts\AlertMessage;
use App\Support\Alerts\CurrentAlerts;
use App\Support\Money;
use App\Support\Period;
use App\Support\Settings;
use App\ViewModels\Reports\DiscountsReport;
use App\ViewModels\Reports\LossesReport;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 375 — after testing 367 and 374: report dates a day early, the per-person losses signal 367 dropped, discount kinds
 * stored from now on, stock with no cost, and "1 units". (§2, money out of alerts, was overruled by Ben: "Euros is fine for
 * alerts" — LossesReportTest keeps asserting the euro figure.)
 */
class AfterTesting375Test extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private User $owner;

    private Genetic $flower;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte', 'timezone' => 'Europe/Madrid']);
        app(ActiveScope::class)->setLocation($this->sede->id);
        Settings::set('enrol_all_sedes', false, SettingType::BOOL);
        Settings::set('charge_rounding_enabled', false, SettingType::BOOL);
        $this->owner = $this->person(Role::OWNER, 'Olga Dueña');
        $this->actingAs($this->owner);
        $this->flower = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Critical Kush']);
    }

    private function person(Role $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole($role->value);
        $user->locations()->sync([$this->sede->id]);

        return $user;
    }

    private function member(): Member
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE, 'carencia_ends_at' => now()->subMonth(),
            'daily_limit_cg' => 100000, 'monthly_limit_cg' => 1000000]);
        (new EnrolMembership)->handle($member, $this->sede, MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'discount_bp' => 0]), ['actor' => $this->owner, 'fee_cents' => 0]);

        return $member;
    }

    private function batch(int $costPerGram = 400): Batch
    {
        return Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->flower->id, 'location_id' => $this->sede->id,
            'remaining_cg' => 10000, 'status' => BatchStatus::OPEN, 'price_per_gram_cents' => 1000, 'cost_per_gram_cents' => $costPerGram, 'expires_on' => now()->addYear()]);
    }

    /** A completed sale with its snapshot (the CommitDispensation carve-out), adjusted from $original when given. */
    private function sale(User $by, int $total, ?int $original = null): void
    {
        $d = Dispensation::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->sede->id, 'member_id' => Member::factory()->create(['organisation_id' => $this->org->id])->id,
            'operator_id' => $by->id, 'total_cents' => $total, 'cash_cents' => $total, 'wallet_cents' => 0, 'original_total_cents' => $original,
            'price_override_by' => $original !== null ? $by->id : null, 'price_override_reason' => $original !== null ? 'Socio habitual' : null,
            'status' => DispensationStatus::COMPLETED, 'dispensed_at' => now(),
        ]);
        DispensationLine::factory()->create(['dispensation_id' => $d->id, 'grams_cg' => 100, 'charged_cg' => 100, 'price_per_gram_cents' => 1000, 'discount_cents' => 0, 'line_total_cents' => $total]);
    }

    // --- 1. The date line is the sede's days ---------------------------------------------------------------------------------

    public function test_every_reports_date_line_reads_the_sedes_days(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-09 05:00', 'UTC')); // 07:00 in Madrid, Friday 9 October
        // The tester's sede: a business day from midnight to midnight, Madrid — bounds 22:00 → 22:00 UTC.
        $this->sede->forceFill(['business_day_cutoff' => '00:00'])->save();

        $label = fn (string $page, string $period): string => (string) preg_replace('/\s+/', ' ',
            strip_tags((string) preg_replace('/.*class="csc-rep-context">(.*?)<\/p>.*/s', '$1', Livewire::test($page)->set('period', $period)->html())));
        foreach ([LossesReportPage::class, DiscountsReportPage::class, ConsumptionReportPage::class] as $page) {
            $this->assertStringEndsWith('09/10/2026', $label($page, 'today'), $page.' · Hoy');
            $this->assertStringEndsWith('05/10/2026 – 11/10/2026', $label($page, 'week'), $page.' · Esta semana');
            $this->assertStringEndsWith('01/10/2026 – 31/10/2026', $label($page, 'month'), $page.' · Este mes');
        }
        $this->assertStringEndsWith('08/10/2026', $label(LossesReportPage::class, 'yesterday'), 'Pérdidas · Ayer');
        $this->assertSame('20261005-20261011', Period::thisWeek($this->sede)->fileRange());
    }

    // --- 3. The per-person signal, beside the sede's -------------------------------------------------------------------------

    /** Ana takes €60.00 and gives €9.60 away (16 %); Bruno takes €500.00 cleanly, so the sede's share stays under 5 %. */
    private function week(): User
    {
        $ana = $this->person(Role::STAFF, 'Ana Barra');
        $this->sale($ana, 4040, original: 5000);
        $this->sale($ana, 1960);
        $this->sale($this->person(Role::MANAGER, 'Bruno Encargado'), 50000);
        // Carla: €20.00 taken and €10.00 given away — 50 %, but under the €50 floor.
        $carla = $this->person(Role::STAFF, 'Carla Nueva');
        $this->sale($carla, 1000, original: 2000);
        $this->sale($carla, 1000);

        return $ana;
    }

    public function test_one_person_over_the_threshold_of_their_own_takings_is_flagged_though_the_sede_is_not(): void
    {
        $this->week();

        $people = LossesReport::peopleAboveThreshold($this->sede);
        $this->assertSame(1, $people['count']);
        $this->assertSame('16', $people['pct']);
        $this->assertNull(LossesReport::yesterdayAboveThreshold($this->sede), 'the sede as a whole is under its threshold');

        $alerts = collect(CurrentAlerts::for($this->org))->where('type', AlertType::LOSSES_PEOPLE_ABOVE_THRESHOLD);
        $this->assertCount(1, $alerts);
        $state = new OwnerAlertState(['type' => AlertType::LOSSES_PEOPLE_ABOVE_THRESHOLD, 'location_id' => $this->sede->id, 'detail' => $alerts->first()['detail']]);
        $state->setRelation('location', $this->sede);
        $text = AlertMessage::text(collect([$state]));
        $this->assertStringContainsString('1 persona por encima del umbral (16 % de lo que cobró)', $text);
        $this->assertStringNotContainsString('Ana', $text, 'no names on a third-party server');
        $this->assertStringContainsString(LossesReport::peopleUrl($this->sede->id, $people['people']), $text); // 377: at the sede, the person
    }

    public function test_the_per_person_dashboard_line_shows_for_reports_viewers_only(): void
    {
        $ana = $this->week();

        $owner = Livewire::actingAs($this->owner)->test(Dashboard::class)->html();
        $this->assertStringContainsString(e(DashboardAlert::LOSSES_PEOPLE_ABOVE_THRESHOLD->label(1)), $owner);
        $this->assertStringContainsString(e(LossesReport::peopleUrl($this->sede->id, [$ana->id])), $owner); // 377: scoped to the sede and the person

        $staff = Livewire::actingAs($ana)->test(Dashboard::class)->html();
        $this->assertStringNotContainsString(e(DashboardAlert::LOSSES_PEOPLE_ABOVE_THRESHOLD->label(1)), $staff);
    }

    // --- 4. The discount kind, stored from now on ----------------------------------------------------------------------------

    public function test_a_local_discount_is_stored_with_its_kind_and_reported_under_it_and_an_older_line_is_unclassified(): void
    {
        $member = $this->member();
        $discount = Discount::factory()->create(['organisation_id' => $this->org->id, 'kind' => DiscountKind::LOCAL, 'mode' => DiscountMode::PERCENT,
            'value_bp' => 1000, 'applies_to' => DiscountAppliesTo::BOTH, 'active' => true]);
        $discount->locations()->sync([$this->sede->id]);
        (new AssignMemberDiscount)->handle($member, $this->owner, ['discount_id' => $discount->id, 'reason' => 'Vecino']);
        $batch = $this->batch();
        $d = (new CommitDispensation)->handle($member, $this->sede, [['genetic_id' => $this->flower->id, 'batch_id' => $batch->id, 'grams_cg' => 200]],
            ['operator_id' => $this->owner->id, 'cash_cents' => 1800]);
        $this->assertSame('LOCAL', $d->lines()->withoutGlobalScopes()->sole()->discount_kind);

        // An older line: discounted, no kind.
        $old = Dispensation::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->sede->id, 'member_id' => $this->member()->id,
            'operator_id' => $this->owner->id, 'total_cents' => 900, 'cash_cents' => 900, 'wallet_cents' => 0, 'status' => DispensationStatus::COMPLETED, 'dispensed_at' => now()]);
        DispensationLine::factory()->create(['dispensation_id' => $old->id, 'discount_cents' => 100, 'line_total_cents' => 900, 'discount_kind' => null]);

        $period = Period::today($this->sede);
        $lines = (new LossesReport($this->org->id, [$this->sede->id], $period))->sections()['mostrador']['lines'];
        $this->assertSame(200, $lines['member_discounts_local']['cents']);
        $this->assertSame(100, $lines['member_discounts_unclassified']['cents']);
        $this->assertSame(300, (new LossesReport($this->org->id, [$this->sede->id], $period))->headline()['member_discounts'], 'the totals are unchanged');

        $types = collect((new DiscountsReport($this->org->id, [$this->sede->id], $period))->tables())->firstWhere('key', 'by_type')->rows;
        $byLabel = collect($types)->pluck('importe', 'tipo');
        $this->assertSame(200, $byLabel[DiscountKind::LOCAL->label()]);
        $this->assertSame(100, $byLabel[__('Sin clasificar (anterior a hoy)')]);
    }

    // --- 5. Stock with no cost ---------------------------------------------------------------------------------------------------

    public function test_stock_lost_from_a_batch_with_no_cost_says_so_instead_of_zero(): void
    {
        (new RecordStockMovement)->handle($this->batch(costPerGram: 0), StockMovementType::ADJUSTMENT, -500, ['operator_id' => $this->owner->id, 'reason' => 'Bolsa rota']);

        $report = new LossesReport($this->org->id, [$this->sede->id], Period::today($this->sede));
        $stock = $report->sections()['existencias'];
        $this->assertSame(1, $stock['no_cost']);
        $this->assertSame(0, $stock['total'], 'no cost is invented; the contribution price is never added');
        $this->assertSame(__('sin coste registrado'), $report->events('existencias')[0]['importe__text']);

        Livewire::test(LossesReportPage::class)->set('period', 'today')
            ->assertSee(__('sin coste registrado'))
            ->assertSee(trans_choice(':count movimiento sin coste: no está en el total|:count movimientos sin coste: no están en el total', 1, ['count' => 1]));
    }

    // --- 6. Unit plurals -------------------------------------------------------------------------------------------------------------

    public function test_the_receipt_says_one_unit_and_three_units_in_both_languages(): void
    {
        $edible = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Gominola', 'product_type' => ProductType::EDIBLE, 'unit_type' => 'UNIT', 'grams_per_unit_cg' => 7]);
        $batch = Batch::factory()->units(20, 20)->create(['organisation_id' => $this->org->id, 'genetic_id' => $edible->id, 'location_id' => $this->sede->id,
            'status' => BatchStatus::OPEN, 'price_per_unit_cents' => 400, 'expires_on' => now()->addYear()]);
        $one = (new CommitDispensation)->handle($this->member(), $this->sede, [['genetic_id' => $edible->id, 'batch_id' => $batch->id, 'units' => 1]], ['operator_id' => $this->owner->id, 'cash_cents' => 400]);
        $three = (new CommitDispensation)->handle($this->member(), $this->sede, [['genetic_id' => $edible->id, 'batch_id' => $batch->id, 'units' => 3]], ['operator_id' => $this->owner->id, 'cash_cents' => 1200]);
        session(['counter.location_id' => $this->sede->id]);

        $this->get(route('counter.pos.receipt', $one->id))->assertOk()->assertSee('1 ud.')->assertDontSee('1 uds');
        $this->get(route('counter.pos.receipt', $three->id))->assertOk()->assertSee('3 uds');
        app()->setLocale('en');
        $this->owner->forceFill(['locale' => 'en'])->save();
        $this->get(route('counter.pos.receipt', $one->id))->assertOk()->assertSee('1 unit')->assertDontSee('1 units');
        $this->get(route('counter.pos.receipt', $three->id))->assertOk()->assertSee('3 units');
    }
}
