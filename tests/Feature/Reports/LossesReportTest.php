<?php

namespace Tests\Feature\Reports;

use App\Actions\Bar\VoidOrder;
use App\Actions\Dispensing\RefundDispensation;
use App\Actions\Stock\AbsorbUnrecordedTopUp;
use App\Actions\Stock\RecordStockMovement;
use App\Actions\Till\CloseTill;
use App\Actions\Till\OpenTill;
use App\Enums\AlertType;
use App\Enums\DashboardAlert;
use App\Enums\DispensationStatus;
use App\Enums\FeePaymentMethod;
use App\Enums\OrderStatus;
use App\Enums\RefundDestination;
use App\Enums\RefundMethod;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Enums\StockMovementType;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Reports\LossesReportPage;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\DispensationLine;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipFeePayment;
use App\Models\MembershipTier;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\OwnerAlertState;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Alerts\AlertMessage;
use App\Support\Alerts\CurrentAlerts;
use App\Support\Money;
use App\Support\Period;
use App\Support\Settings;
use App\Support\Spreadsheet\ReportExport;
use App\ViewModels\Reports\DiscountsReport;
use App\ViewModels\Reports\LossesReport;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 367 — *Pérdidas*. Ben: "a decent report log for the owner to see when there's stuff down — lots of discount being
 * added etc. — like a loss page, so they know how much is lost with people getting more bud". One week at one sede, a known
 * example of each kind, every figure hand-computed below.
 *
 * Hand-computed headline (member discounts beside it, not in it):
 *   Mostrador    adjustment €30 → €25 (+5.00) · €30 → €34 recovered (−4.00) · waived fee (+20.00)        = 21.00
 *   Peso de más  1.10 g charged as 1.00 g at €10/g                                                        =  1.00
 *   Existencias  2 g merma, cost €4/g (€10/g contribution): at cost                                       =  8.00
 *   Caja         dispensary short by €12                                                                  = 12.00
 *   Devoluciones €10 refund · voided €15 order                                                            = 25.00
 *   Total                                                                                                 = 67.00
 */
class LossesReportTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private Location $norte;

    private User $ana;

    private User $bruno;

    private User $owner;

    private CarbonImmutable $monday;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        $this->norte = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte']);
        app(ActiveScope::class)->setLocation($this->centro->id);
        $this->ana = $this->person(Role::STAFF, 'Ana Barra', [$this->centro]);
        $this->bruno = $this->person(Role::MANAGER, 'Bruno Encargado', [$this->centro]);
        $this->owner = $this->person(Role::OWNER, 'Olga Dueña', [$this->centro, $this->norte]);
        // A Wednesday at noon, in a week that started on Monday the 5th.
        $this->monday = CarbonImmutable::parse('2026-10-05 12:00:00');
        $this->travelTo($this->monday->addDays(2));
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

    /**
     * A completed (or voided) dispensation with its full snapshot — the CommitDispensation carve-out (DECISIONS 291).
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function dispensation(User $by, int $total, array $lines = [], ?int $original = null, ?Member $member = null, bool $voided = false, ?Location $at = null): Dispensation
    {
        $d = Dispensation::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => ($at ?? $this->centro)->id, 'member_id' => ($member ?? $this->member())->id,
            'operator_id' => $by->id, 'total_cents' => $total, 'cash_cents' => $total, 'wallet_cents' => 0,
            'original_total_cents' => $original, 'price_override_by' => $original !== null ? $by->id : null,
            'price_override_reason' => $original !== null ? 'Ajuste de prueba' : null,
            'status' => $voided ? DispensationStatus::VOIDED : DispensationStatus::COMPLETED, 'dispensed_at' => now(),
            'voided_at' => $voided ? now() : null, 'voided_by' => $voided ? $by->id : null, 'void_reason' => $voided ? 'Error de báscula' : null,
        ]);
        foreach ($lines as $line) {
            DispensationLine::factory()->create(['dispensation_id' => $d->id] + $line);
        }

        return $d;
    }

    private function waiver(User $by, int $amount, ?CarbonImmutable $at = null): void
    {
        $membership = Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $this->member()->id, 'location_id' => $this->centro->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
        ]);
        MembershipFeePayment::factory()->create([
            'membership_id' => $membership->id, 'amount_cents' => $amount, 'method' => FeePaymentMethod::WAIVED,
            'reason' => 'Socio fundador', 'recorded_by' => $by->id, 'paid_at' => $at ?? now(),
        ]);
    }

    private function batch(int $remainingCg = 1000, int $reserveCg = 0, ?Location $at = null): Batch
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia', 'unit_type' => 'WEIGHT']);

        return Batch::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => ($at ?? $this->centro)->id, 'genetic_id' => $genetic->id,
            'remaining_cg' => $remainingCg, 'reserve_cg' => $reserveCg, 'initial_cg' => $remainingCg + $reserveCg,
            'cost_per_gram_cents' => 400, 'price_per_gram_cents' => 1000,
        ]);
    }

    /** The week of the class docblock. */
    private function fixture(): void
    {
        // Peso de más: 1.10 g weighed, 1.00 g charged, at €10/g — Ana.
        $this->dispensation($this->ana, 1000, [['grams_cg' => 110, 'charged_cg' => 100, 'price_per_gram_cents' => 1000, 'discount_cents' => 0, 'line_total_cents' => 1000]]);
        // Adjustments: down by Bruno (€5 given), up by Ana (€4 recovered).
        $this->dispensation($this->bruno, 2500, [['grams_cg' => 300, 'charged_cg' => 300, 'price_per_gram_cents' => 1000, 'discount_cents' => 0, 'line_total_cents' => 3000]], original: 3000);
        $this->dispensation($this->ana, 3400, [['grams_cg' => 300, 'charged_cg' => 300, 'price_per_gram_cents' => 1000, 'discount_cents' => 0, 'line_total_cents' => 3000]], original: 3000);
        // Member discounts — beside the total, never in it: €3 to a member who is staff, €2 to another member.
        $staffMember = $this->member();
        User::factory()->create(['name' => 'Carla Personal', 'member_id' => $staffMember->id]);
        $this->dispensation($this->ana, 2700, [['grams_cg' => 300, 'charged_cg' => 300, 'price_per_gram_cents' => 1000, 'discount_cents' => 300, 'line_total_cents' => 2700]], member: $staffMember);
        $this->dispensation($this->ana, 1800, [['grams_cg' => 200, 'charged_cg' => 200, 'price_per_gram_cents' => 1000, 'discount_cents' => 200, 'line_total_cents' => 1800]]);
        // A waived €20 fee — Olga.
        $this->waiver($this->owner, 2000);
        // A 2 g merma — Olga.
        (new RecordStockMovement)->handle($this->batch(), StockMovementType::MERMA, -200, ['actor' => $this->owner, 'operator_id' => $this->owner->id, 'reason' => 'Caducado']);
        // The dispensary till short by €12 at the close — Olga closed it.
        $session = (new OpenTill)->handle($this->centro, 'Caja 1', 10000, ['operator_id' => $this->owner->id]);
        (new CloseTill)->handle($session, 8800, $this->owner);
        // A €10 refund to the wallet — Bruno.
        $refunded = $this->dispensation($this->ana, 1000, [['grams_cg' => 100, 'charged_cg' => 100, 'price_per_gram_cents' => 1000, 'discount_cents' => 0, 'line_total_cents' => 1000]]);
        (new RefundDispensation)->handle($refunded, $this->bruno, ['amount_cents' => 1000, 'grams_cg' => 0, 'destination' => RefundDestination::STOCK, 'method' => RefundMethod::WALLET, 'reason' => 'Producto en mal estado']);
        // A voided €15 bar order — Bruno.
        $order = Order::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->centro->id, 'operator_id' => $this->ana->id, 'member_id' => $this->member()->id,
            'items' => [['article_id' => null, 'name' => 'Varios', 'unit_price_cents' => 1500, 'qty' => 1, 'line_total_cents' => 1500, 'reference' => 'Camiseta']],
            'total_cents' => 1500, 'cash_cents' => 0, 'wallet_cents' => 1500, 'status' => OrderStatus::COMPLETED,
        ]);
        (new VoidOrder)->handle($order, $this->bruno, 'Cobrado dos veces');
    }

    private function week(): Period
    {
        return Period::custom($this->monday, $this->monday->addDays(6), $this->centro);
    }

    private function report(?array $locationIds = null, ?Period $period = null): LossesReport
    {
        return new LossesReport($this->org->id, $locationIds ?? [$this->centro->id], $period ?? $this->week());
    }

    // 1 — each section's total ------------------------------------------------------------------------------------------------

    public function test_each_section_total_is_the_hand_computed_figure(): void
    {
        $this->fixture();
        $sections = $this->report()->sections();

        $counter = $sections['mostrador']['lines'];
        $this->assertSame(500, $counter['overrides_given']['cents']);
        $this->assertSame(-400, $counter['overrides_recovered']['cents']); // recovered, subtracted
        $this->assertSame(2000, $counter['waived_fees']['cents']);
        $this->assertSame(2100, $sections['mostrador']['total']);

        $weight = $sections['peso']['lines'];
        $this->assertSame(10, $weight['weight_over']['grams']);   // 0.10 g
        $this->assertSame(100, $weight['weight_over']['cents']);  // €1.00, at the line's own price
        $this->assertSame(100, $sections['peso']['total']);

        $stock = $sections['existencias']['lines'];
        $this->assertSame(200, $stock['merma']['grams']);
        $this->assertSame(800, $stock['merma']['cents']);        // at cost
        $this->assertSame(2000, $stock['merma']['contribution']); // at the batch's contribution price
        $this->assertSame(800, $sections['existencias']['total']);

        $this->assertSame(1200, $sections['caja']['lines']['till_short']['cents']);
        $this->assertSame(1200, $sections['caja']['total']);

        $returns = $sections['devoluciones']['lines'];
        $this->assertSame(1000, $returns['refunds']['cents']);
        $this->assertSame(1500, $returns['voided_orders']['cents']);
        $this->assertSame(1, $returns['voided_orders']['count']);
        $this->assertSame(2500, $sections['devoluciones']['total']);
    }

    // 2 — the headline ------------------------------------------------------------------------------------------------------------

    public function test_the_headline_is_the_sum_of_the_sections_with_member_discounts_beside_it(): void
    {
        $this->fixture();
        $this->waiver($this->owner, 500, $this->monday->subDays(3)); // the week before: €5 lost
        $report = $this->report();
        $h = $report->headline();

        $this->assertSame(6700, $h['total']);
        $this->assertSame(array_sum(array_column($report->sections(), 'total')), $h['total']);
        $this->assertSame(500, $h['member_discounts']); // €3 + €2, not in the total
        $this->assertSame(300, $h['staff_discounts']);  // the staff member's €3, apart
        $this->assertSame(500, $h['previous_total']);
        // Takings: 10 + 25 + 34 + 27 + 18 + 10 = €124 (the voided order never counts).
        $this->assertSame(12400, $h['takings']);

        $chips = collect($report->summary())->keyBy('key');
        $this->assertSame(Money::fromCents(6700)->formatted(), $chips['total']['value']);
        $this->assertStringContainsString('54.0 % de lo recaudado', $chips['share']['value']);
        $this->assertStringContainsString('+'.Money::fromCents(6200)->formatted().' frente a la semana anterior', $chips['previous']['value']);
        $this->assertSame(Money::fromCents(500)->formatted(), $chips['member_discounts']['value']);

        // One bar per day across the week, the Wednesday carrying everything.
        $series = $report->series();
        $this->assertCount(7, $series['values']);
        $this->assertSame(6700, $series['values'][2]);
    }

    // 3 — by person ----------------------------------------------------------------------------------------------------------------

    public function test_each_event_goes_to_its_operator_of_record_and_the_rows_sum_to_the_headline(): void
    {
        $this->fixture();
        $report = $this->report();
        $rows = collect($report->byPerson())->keyBy('persona');

        // Ana: 0.10 g over (+1.00), raised a price (−4.00).
        $this->assertSame(100, $rows['Ana Barra']['peso']);
        $this->assertSame(-400, $rows['Ana Barra']['mostrador']);
        $this->assertSame(-300, $rows['Ana Barra']['total']);
        $this->assertSame(500, $rows['Ana Barra']['descuentos_socio']); // information, outside the total
        // Bruno: lowered a price (+5.00), refunded €10, voided a €15 order.
        $this->assertSame(500, $rows['Bruno Encargado']['mostrador']);
        $this->assertSame(2500, $rows['Bruno Encargado']['devoluciones']);
        $this->assertSame(3000, $rows['Bruno Encargado']['total']);
        // Olga: waived €20, merma €8 at cost, closed the till €12 short.
        $this->assertSame(2000, $rows['Olga Dueña']['mostrador']);
        $this->assertSame(800, $rows['Olga Dueña']['existencias']);
        $this->assertSame(1200, $rows['Olga Dueña']['caja']);
        $this->assertSame(4000, $rows['Olga Dueña']['total']);

        $this->assertSame($report->headline()['total'], $rows->sum('total'));
        // Their takings beside it: Ana served 10 + 34 + 27 + 18 + 10 = €99.
        $this->assertSame(9900, $rows['Ana Barra']['recaudado']);
    }

    // 4 — consistency --------------------------------------------------------------------------------------------------------------

    public function test_adjustments_and_waived_fees_equal_the_discounts_report(): void
    {
        $this->fixture();
        $losses = $this->report()->sections()['mostrador']['lines'];
        $discounts = collect((new DiscountsReport($this->org->id, [$this->centro->id], $this->week()))->summary())->keyBy('key');

        $this->assertSame($discounts['overrides']['value'], Money::fromCents($losses['overrides_given']['cents'])->formatted());
        $this->assertSame($discounts['overrides_recovered']['value'], Money::fromCents(-$losses['overrides_recovered']['cents'])->formatted());
        $this->assertSame($discounts['waivers']['value'], Money::fromCents($losses['waived_fees']['cents'])->formatted());
        $this->assertSame($discounts['member_discounts']['value'], Money::fromCents($this->report()->headline()['member_discounts'])->formatted());
    }

    // 5 — scoping ------------------------------------------------------------------------------------------------------------------

    public function test_a_manager_sees_only_their_sede_the_owner_all_and_staff_nothing(): void
    {
        $this->fixture();
        $this->waiver($this->owner, 4321); // at Centro
        $membership = Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $this->member()->id, 'location_id' => $this->norte->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
        ]);
        MembershipFeePayment::factory()->create(['membership_id' => $membership->id, 'amount_cents' => 9876, 'method' => FeePaymentMethod::WAIVED,
            'reason' => 'Norte', 'recorded_by' => $this->owner->id, 'paid_at' => now()]);

        $manager = Livewire::actingAs($this->bruno)->test(LossesReportPage::class)->set('period', 'week')->assertOk();
        $manager->assertSee(Money::fromCents(4321)->formatted())->assertDontSee(Money::fromCents(9876)->formatted());
        Livewire::actingAs($this->bruno)->test(LossesReportPage::class)->set('scope', $this->norte->id)->assertForbidden();
        Livewire::actingAs($this->bruno)->test(LossesReportPage::class)->set('scope', 'all')->assertForbidden();

        Livewire::actingAs($this->owner)->test(LossesReportPage::class)->set('period', 'week')->assertOk()
            ->assertSee(Money::fromCents(9876)->formatted())->assertSee(Money::fromCents(4321)->formatted())
            ->assertSee(__('Por sede'));

        Livewire::actingAs($this->ana)->test(LossesReportPage::class)->assertForbidden();
    }

    // 6 — exclusions ---------------------------------------------------------------------------------------------------------------

    public function test_an_unrecorded_top_up_is_no_loss_and_a_voided_sale_counts_once(): void
    {
        $batch = $this->batch(remainingCg: 500, reserveCg: 1000);
        (new AbsorbUnrecordedTopUp)->handle($batch, 300, ['operator_id' => $this->owner->id]); // «Rellenado sin registrar»
        // A voided sale that carried an adjustment and a member discount: one void, never a discount or an adjustment too.
        $this->dispensation($this->ana, 2500, [['grams_cg' => 300, 'charged_cg' => 300, 'price_per_gram_cents' => 1000, 'discount_cents' => 200, 'line_total_cents' => 2500]], original: 3000, voided: true);

        $report = $this->report();
        $sections = $report->sections();
        $this->assertSame(0, $sections['existencias']['total']);
        $this->assertSame(0, $sections['mostrador']['total']);
        $this->assertSame(0, $report->headline()['member_discounts']);
        $this->assertSame(2500, $sections['devoluciones']['lines']['voided_dispensations']['cents']);
        $this->assertSame(2500, $report->headline()['total']);

        // A void corrected by a fresh sale (the canon: void + a new row linked by reversal_of_id) is a correction, not a loss.
        $voided = Dispensation::query()->withoutGlobalScopes()->where('status', DispensationStatus::VOIDED)->firstOrFail();
        $this->dispensation($this->ana, 2500, [['grams_cg' => 300, 'charged_cg' => 300, 'price_per_gram_cents' => 1000, 'discount_cents' => 0, 'line_total_cents' => 2500]])
            ->forceFill(['reversal_of_id' => $voided->id])->save();
        $this->assertSame(0, $this->report()->sections()['devoluciones']['total']);
    }

    // 7 — the morning summary, the dashboard and the threshold -----------------------------------------------------------------------

    public function test_yesterdays_losses_reach_the_dashboard_and_the_alert_fires_above_the_threshold(): void
    {
        $this->travelTo($this->monday->addDay()); // Tuesday noon: «yesterday» is Monday
        $this->travel(-1)->days();
        // Monday: €100 taken, a €6 adjustment down — 6 %, over the 5 % default.
        $this->dispensation($this->bruno, 9400, [['grams_cg' => 1000, 'charged_cg' => 1000, 'price_per_gram_cents' => 1000, 'discount_cents' => 0, 'line_total_cents' => 10000]], original: 10000);
        $this->dispensation($this->ana, 600, [['grams_cg' => 60, 'charged_cg' => 60, 'price_per_gram_cents' => 1000, 'discount_cents' => 0, 'line_total_cents' => 600]]);
        $this->travel(1)->days();

        $day = LossesReport::yesterday($this->centro);
        $this->assertSame(600, $day['total']);
        $this->assertSame(10000, $day['takings']);

        $alerts = collect(CurrentAlerts::for($this->org))->where('type', AlertType::LOSSES_ABOVE_THRESHOLD);
        $this->assertCount(1, $alerts);
        $alert = $alerts->first();
        $this->assertSame($this->centro->id, $alert['location_id']);
        $state = new OwnerAlertState(['type' => AlertType::LOSSES_ABOVE_THRESHOLD, 'location_id' => $this->centro->id, 'detail' => $alert['detail']]);
        $state->setRelation('location', $this->centro);
        $text = AlertMessage::text(collect([$state]));
        $this->assertStringContainsString('Pérdidas ayer: '.Money::fromCents(600)->formatted().' (6.0 %)', $text);
        $this->assertStringContainsString(LossesReportPage::getUrl(['period' => 'yesterday']), $text);

        // The dashboard: the yesterday line for a report holder, and the alert row.
        $html = Livewire::actingAs($this->owner)->test(Dashboard::class)->html();
        $this->assertStringContainsString('data-losses-yesterday', $html);
        $this->assertStringContainsString(Money::fromCents(600)->formatted(), $html);
        $this->assertStringContainsString(DashboardAlert::LOSSES_ABOVE_THRESHOLD->value, $html);
        $this->assertStringContainsString(e(DashboardAlert::LOSSES_ABOVE_THRESHOLD->label(1)), $html);
        // Staff never see either (291's rule, kept).
        $staff = Livewire::actingAs($this->ana)->test(Dashboard::class)->html();
        $this->assertStringNotContainsString('data-losses-yesterday', $staff);
        $this->assertStringNotContainsString(DashboardAlert::LOSSES_ABOVE_THRESHOLD->value, $staff);

        // Raise the sede's threshold above 6 %: no alert.
        Settings::set('losses_alert_threshold_pct', 7, SettingType::INT, $this->centro->id);
        $this->assertCount(0, collect(CurrentAlerts::for($this->org))->where('type', AlertType::LOSSES_ABOVE_THRESHOLD));
    }

    // 8 — export -----------------------------------------------------------------------------------------------------------------

    public function test_the_csv_and_pdf_carry_the_headline_the_sections_and_the_people(): void
    {
        $this->fixture();
        $report = $this->report();

        $csv = $report->csv();
        $this->assertStringContainsString(__('Total perdido'), $csv);
        $this->assertMatchesRegularExpression('/67[.,]00/', $csv);
        foreach ([__('Descuentos y regalos en el mostrador'), __('Peso de más'), __('Existencias perdidas'), __('Diferencias de caja'), __('Devoluciones y anulaciones')] as $section) {
            $this->assertStringContainsString($section, $csv);
        }
        foreach (['Ana Barra', 'Bruno Encargado', 'Olga Dueña'] as $name) {
            $this->assertStringContainsString($name, $csv);
        }

        $keys = array_map(fn ($t) => $t->key, $report->tables());
        $this->assertSame('by_person', $keys[0]);
        $this->assertContains('sections', $keys);
        $this->assertContains('total', array_column($report->summary(), 'key'));
        $this->assertNotSame('', ReportExport::pdf($report->title(), $report->summary(), $report->tables(), 'Sede Centro', '05/10/2026 – 11/10/2026'));

        Livewire::actingAs($this->owner)->test(LossesReportPage::class)->call('exportCsv')->assertFileDownloaded();
    }

    // The detail ---------------------------------------------------------------------------------------------------------------------

    public function test_each_section_opens_its_events_and_each_event_links_to_its_record(): void
    {
        $this->fixture();
        $events = $this->report()->events('existencias');
        $this->assertCount(1, $events);
        $this->assertSame('Olga Dueña', $events[0]['persona']);
        $this->assertSame('Caducado', $events[0]['motivo']);
        $this->assertNotEmpty($events[0]['fecha__url']);

        $html = Livewire::actingAs($this->owner)->test(LossesReportPage::class)->set('period', 'week')->set('section', 'caja')->html();
        $this->assertStringContainsString('data-losses-detail="caja"', $html);
        $this->assertStringContainsString('Caja 1', $html);
    }
}
