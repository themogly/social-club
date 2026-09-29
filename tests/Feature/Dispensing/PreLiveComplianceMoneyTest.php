<?php

namespace Tests\Feature\Dispensing;

use App\Actions\Attendance\ResolveMemberEligibility;
use App\Actions\Dispensing\ResolveMemberLimits;
use App\Actions\Members\SetMemberDebtLimit;
use App\Actions\Pricing\SaveGeneticPrice;
use App\Actions\Till\OpenTill;
use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\BatchStatus;
use App\Enums\DispensationStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Enums\WalletTransactionType;
use App\Filament\Pages\ManageSettings;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\TillSession as TillSessionScreen;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\DispensationLine;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\BusinessDay;
use App\Support\CounterOperator;
use App\Support\Money;
use App\Support\Period;
use App\Support\Settings;
use App\Support\Wallet;
use App\ViewModels\Dashboard;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 271 — the pre-live audits' compliance and money findings, each pinned where it bit.
 *
 * The timezone investigation found the monthly gram cap starting its month at local midnight while every report
 * started it at the 06:00 cutoff, and the counter hub counting "today" in UTC. The code-style and admin audits found
 * typed cash read "1.250" as €1,25, a tab filled from the pre-override total, a debt setting with two meanings, two
 * base prices for one variety, legal thresholds and retention periods that saved at 0 or below, a sede form that 500'd,
 * a misprinted stock intake, and two variety fields nothing read. The demo was all-UTC, which hid the first two.
 */
class PreLiveComplianceMoneyTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $madrid;

    private User $owner;

    private Genetic $genetic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->madrid = Location::factory()->create([
            'organisation_id' => $this->org->id, 'timezone' => 'Europe/Madrid', 'business_day_cutoff' => '06:00',
        ]);
        app(ActiveScope::class)->setLocation($this->madrid->id);

        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->madrid->id]);

        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function member(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subYear(),
            'daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000,
        ]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->madrid->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
            'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);

        return $member;
    }

    /** A completed dispensation at a Madrid wall-clock time. */
    private function dispenseAt(Member $member, string $madridTime, int $gramsCg = 100): void
    {
        $dispensation = Dispensation::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->madrid->id, 'member_id' => $member->id,
            'status' => DispensationStatus::COMPLETED, 'total_cents' => 1000, 'cash_cents' => 1000, 'wallet_cents' => 0,
            'dispensed_at' => CarbonImmutable::parse($madridTime, 'Europe/Madrid')->utc(),
        ]);
        DispensationLine::factory()->create(['dispensation_id' => $dispensation->id, 'genetic_id' => $this->genetic->id, 'grams_cg' => $gramsCg]);
    }

    // --- The business day and month -----------------------------------------------------------------------------------

    public function test_a_dispensation_after_midnight_on_the_1st_belongs_to_the_previous_business_month_for_the_cap_and_the_report(): void
    {
        $member = $this->member();
        $this->dispenseAt($member, '2026-09-30 20:00');
        $this->dispenseAt($member, '2026-10-01 01:30');
        $this->dispenseAt($member, '2026-10-01 05:00');

        // At 05:30 on the 1st it is still September's business day: the cap sees all three (it used to see ONE —
        // the gap on the 1st in which grams dispensed after midnight were invisible to the monthly cap).
        $early = (new ResolveMemberLimits)->handle($member, $this->madrid, CarbonImmutable::parse('2026-10-01 05:30', 'Europe/Madrid'));
        $this->assertSame(300, $early->monthlyUsedCg);

        // At noon on the 1st, October has started — and none of the three is charged to it (it used to count two).
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00', 'Europe/Madrid'));
        $noon = (new ResolveMemberLimits)->handle($member, $this->madrid);
        $this->assertSame(0, $noon->monthlyUsedCg);

        // …which is exactly the month the reports use.
        $this->assertEquals(Period::thisMonth($this->madrid)->start, CarbonImmutable::parse('2026-10-01 06:00', 'Europe/Madrid')->utc());
        $dashboard = new Dashboard($this->org->id, [$this->madrid->id], Period::thisMonth($this->madrid));
        $this->assertSame(0, $dashboard->gramsDispensedCg());
    }

    public function test_the_counter_hub_today_is_the_business_day_not_the_utc_day(): void
    {
        $member = $this->member();
        $this->dispenseAt($member, '2026-09-26 23:30');
        $this->dispenseAt($member, '2026-09-27 01:30');
        $this->travelTo(CarbonImmutable::parse('2026-09-27 05:30', 'Europe/Madrid'));

        // The same night, one business day — the hub used to reset at 02:00 Madrid (UTC midnight) and show one.
        $this->assertSame(2, (new Dashboard($this->org->id, [$this->madrid->id], Period::today($this->madrid)))->transactionCount());
        $this->assertEquals(Period::today($this->madrid)->bounds(), Period::today()->bounds(), 'a location-less "today" is the sede in scope\'s');
    }

    public function test_a_custom_range_is_whole_business_days(): void
    {
        $range = Period::custom(CarbonImmutable::parse('2026-09-27'), CarbonImmutable::parse('2026-09-27'), $this->madrid);

        $this->assertEquals(CarbonImmutable::parse('2026-09-27 06:00', 'Europe/Madrid')->utc(), $range->start);
        $this->assertEquals(CarbonImmutable::parse('2026-09-28 06:00', 'Europe/Madrid')->utc(), $range->end);
    }

    public function test_times_a_person_reads_are_the_sedes_local_time(): void
    {
        $this->assertSame('27/09/2026 01:30', local_datetime(CarbonImmutable::parse('2026-09-26 23:30:00', 'UTC'), 'd/m/Y H:i', $this->madrid));
        $this->assertSame('', local_datetime(null));
    }

    /** Prompt 274 — printing a time resolves the sede once per request, not once per row (the till report grew with it). */
    public function test_printing_many_times_resolves_the_sede_once(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        foreach (range(1, 20) as $i) {
            local_datetime(now()->subMinutes($i));
        }
        $locationQueries = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'from "locations"'))->count();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(1, $locationQueries);
    }

    /** Prompt 275 — a date-only field compares against the sede's BUSINESS date, not the UTC calendar date. */
    public function test_a_batch_that_expired_yesterday_is_still_dispensable_until_the_cutoff(): void
    {
        $batch = Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->madrid->id,
            'initial_cg' => 1000, 'remaining_cg' => 1000, 'status' => BatchStatus::OPEN, 'expires_on' => '2026-09-27',
        ]);
        $dispensable = fn (): bool => Batch::query()->withoutGlobalScopes()->whereKey($batch->id)->dispensable($this->madrid->id)->exists();

        // 28 Sep 03:00 Madrid is still business day 27 Sep (06:00 cutoff): the lote that expires on the 27th is in date.
        $this->travelTo(CarbonImmutable::parse('2026-09-28 03:00', 'Europe/Madrid'));
        request()->attributes->replace([]);
        $this->assertSame('2026-09-27', BusinessDay::today($this->madrid));
        $this->assertTrue($dispensable());

        // After the cutoff it is the 28th, and the lote is expired.
        $this->travelTo(CarbonImmutable::parse('2026-09-28 07:00', 'Europe/Madrid'));
        request()->attributes->replace([]);
        $this->assertFalse($dispensable());
    }

    // --- Typed money: one strict rule ------------------------------------------------------------------------------

    public function test_typed_euros_are_read_one_unambiguous_way(): void
    {
        // Prompt 306 — the FULLY written "1.250,00" / "1,250.00" can only mean one number, so it is read; a lone "1.250"
        // (a thousand, or one?) is still refused.
        foreach (['1250' => 125000, '1250,5' => 125050, '1250.50' => 125050, '0,05' => 5, ' 20 ' => 2000, '1.250,00' => 125000, '1,250.00' => 125000] as $typed => $cents) {
            $this->assertSame($cents, Money::parseTyped((string) $typed), "'{$typed}'");
        }
        foreach (['1.250', '1,250', '12,345', '1.25,00', '-5', 'abc', '', '€5'] as $typed) {
            $this->assertNull(Money::parseTyped($typed), "'{$typed}' was accepted");
        }
    }

    public function test_the_blind_count_refuses_1_250_rather_than_closing_at_one_euro_twenty_five(): void
    {
        $this->actingAs($this->owner);
        session(['counter.location_id' => $this->madrid->id]);
        CounterOperator::set($this->owner);
        $session = (new OpenTill)->handle($this->madrid, 'POS-1', 10000, ['operator_id' => $this->owner->id]);

        Livewire::test(TillSessionScreen::class)
            ->set('countInput', '1.250')
            ->call('submitCount')
            ->assertSet('flashMessage', __('El importe contado no es válido.'));

        $this->assertNull(TillSession::query()->withoutGlobalScopes()->findOrFail($session->id)->closed_at);
    }

    // --- The tab reads the charged total ---------------------------------------------------------------------------

    public function test_a_tab_after_a_price_override_owes_the_overridden_total_minus_the_cash(): void
    {
        Settings::set('wallet_debt_allowed', true, SettingType::BOOL, $this->madrid->id);
        GeneticPrice::create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->madrid->id,
            'tier_id' => null, 'price_per_gram_cents' => 1000, 'active' => true,
        ]);
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->madrid->id,
            'initial_cg' => 50000, 'remaining_cg' => 50000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(),
        ]);
        $member = $this->member();
        (new SetMemberDebtLimit)->handle($member, $this->owner, 10000, 'Aprobado');

        $this->actingAs($this->owner);
        session(['counter.location_id' => $this->madrid->id]);
        CounterOperator::set($this->owner);
        (new OpenTill)->handle($this->madrid, 'POS-1', 10000, ['operator_id' => $this->owner->id]);

        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('chooseGenetic', $this->genetic->id)->set('weightInput', '5')->call('addLine') // €50,00
            ->set('priceOverrideEuros', '30')->set('priceOverrideReason', 'Producto defectuoso')      // charged €30,00
            ->set('cashTendered', '10')                                                                 // €10 handed
            ->call('commitOnTab')
            ->assertSet('flashType', 'success');

        $this->assertSame(-2000, Wallet::balance($member->id, $this->madrid->id), 'the tab should hold €20, not the pre-override €40');
    }

    // --- One debt rule ---------------------------------------------------------------------------------------------

    public function test_an_approved_tab_does_not_block_the_counter_at_the_default_club_cap(): void
    {
        Settings::set('wallet_debt_allowed', true, SettingType::BOOL);
        $member = $this->member();
        (new SetMemberDebtLimit)->handle($member, $this->owner, 2000, 'Aprobado');
        (new RecordWalletTransaction)->handle($member, $this->madrid, -500, WalletTransactionType::CONTRIBUTION);

        $debt = fn (): array => collect((new ResolveMemberEligibility)->handle($member, $this->madrid, 'counter')->rules)->firstWhere('rule', 'debt');

        // Owing €5 of an approved €20, club cap 0 (= no cap): not over any threshold — it used to BLOCK here.
        $this->assertTrue($debt()['satisfied']);

        // Beyond the approved tab, it blocks.
        (new SetMemberDebtLimit)->handle($member, $this->owner, 300, 'Reducido');
        $this->assertFalse($debt()['satisfied']);

        // A club cap below what they owe blocks too.
        (new SetMemberDebtLimit)->handle($member, $this->owner, 2000, 'Aprobado');
        Settings::set('wallet_debt_limit_cents', 400, SettingType::CENTS);
        $this->assertFalse($debt()['satisfied']);
    }

    // --- One price per sede and tarifa -----------------------------------------------------------------------------

    public function test_a_second_base_price_for_the_same_sede_is_refused(): void
    {
        (new SaveGeneticPrice)->handle($this->genetic, $this->madrid, null, 1000);

        $this->expectException(InvalidArgumentException::class);
        (new SaveGeneticPrice)->handle($this->genetic, $this->madrid, null, 900);
    }

    // --- Settings that feed legal gates and irreversible jobs have bounds ----------------------------------------------

    public function test_legal_thresholds_and_retention_cannot_be_saved_at_nonsense_values(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ManageSettings::class)
            ->set('data.min_age', 0)
            ->set('data.carencia_days', -1)
            ->set('data.daily_limit_g', 50)
            ->set('data.monthly_limit_g', 10)
            ->set('data.gauge_warning_pct', 90)
            ->set('data.gauge_alert_pct', 10)
            ->set('data.data_retention_days', 0)
            ->set('data.audit_retention_days', -30)
            ->set('data.signed_url_ttl_seconds', 31536000)
            ->call('save')
            ->assertHasErrors([
                'data.min_age', 'data.carencia_days', 'data.monthly_limit_g', 'data.gauge_alert_pct',
                'data.data_retention_days', 'data.audit_retention_days', 'data.signed_url_ttl_seconds',
            ]);
    }

    public function test_the_nightly_anonymisation_ignores_a_retention_stored_below_the_floor(): void
    {
        Settings::set('data_retention_days', 0, SettingType::INT); // stored before the form had bounds
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'left_at' => now()->subDays(10)]);

        $this->artisan('members:purge')->assertSuccessful();

        $this->assertNull($member->fresh()->anonymised_at, 'a 0-day retention anonymised a member who left ten days ago');
    }

    public function test_a_sede_needs_a_timezone_and_an_aforo_of_at_least_one(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(EditLocation::class, ['record' => $this->madrid->getRouteKey()])
            ->fillForm(['timezone' => null, 'capacity' => null])
            ->call('save')
            ->assertHasFormErrors(['timezone' => 'required', 'capacity' => 'required']);

        Livewire::test(EditLocation::class, ['record' => $this->madrid->getRouteKey()])
            ->fillForm(['capacity' => -1])
            ->call('save')
            ->assertHasFormErrors(['capacity']);
    }

    // --- The variety's own fields ----------------------------------------------------------------------------------

    public function test_publicada_decides_the_member_menu_and_the_counter_shows_the_photo(): void
    {
        GeneticPrice::create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->madrid->id,
            'tier_id' => null, 'price_per_gram_cents' => 1000, 'active' => true,
        ]);
        $this->genetic->forceFill(['published' => false, 'images' => ['genetics/photo.jpg'], 'name' => 'Oculta Kush'])->save();
        $member = $this->member();

        $this->actingAs($member, 'member')->get(route('socio.menu'))->assertOk()->assertDontSee('Oculta Kush');

        $this->genetic->forceFill(['published' => true])->save();
        $this->actingAs($member, 'member')->get(route('socio.menu'))->assertOk()->assertSee('Oculta Kush');

        $this->actingAs($this->owner);
        session(['counter.location_id' => $this->madrid->id]);
        CounterOperator::set($this->owner);
        (new OpenTill)->handle($this->madrid, 'POS-1', 10000, ['operator_id' => $this->owner->id]);
        Livewire::test(DispensaryPos::class)->call('selectMember', $member->id)
            ->assertSeeHtml('data-genetic-thumb')
            ->assertSeeHtml('genetics/photo.jpg');
    }
}
