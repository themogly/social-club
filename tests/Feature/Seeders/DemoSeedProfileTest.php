<?php

namespace Tests\Feature\Seeders;

use App\Actions\Attendance\ResolveMemberEligibility;
use App\Actions\Dispensing\CommitDispensation;
use App\Enums\DispensationStatus;
use App\Enums\MemberKind;
use App\Enums\MemberStatus;
use App\Models\Batch;
use App\Models\ExpenseCategory;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberSanction;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\Period;
use App\Support\Settings;
use App\Support\StockCeiling;
use App\Support\Wallet;
use App\ViewModels\Reports\BarSalesReport;
use App\ViewModels\Reports\FinancialReport;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Prompt 70 — the demo seed reads correctly in the active locale (not English UI over Spanish data),
 * and the demo settings PROFILE turns the optional features on (as rows, never touching the conservative
 * Settings::DEFAULTS) with real data behind each one, so the features can actually be seen.
 */
class DemoSeedProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake(); // a seeded debit may cross the low-balance threshold — never hit a real channel
        $this->travelTo(CarbonImmutable::parse('2026-07-20 12:00:00')); // fortnight stays inside "this month"
    }

    /** Run the real demo seed chain in the given locale (the seeder is local-only). */
    private function seedDemo(string $locale): void
    {
        $this->app['env'] = 'local';
        app()->setLocale($locale);
        $this->seed(DatabaseSeeder::class);
        app(ActiveScope::class)->setOrganisation(Organisation::firstOrFail()->id);
    }

    public function test_the_stock_ceiling_is_per_sede_and_a_deliberate_demo(): void
    {
        $this->seedDemo('es');
        $org = Organisation::firstOrFail();
        $locations = Location::query()->where('organisation_id', $org->id)->get();

        // Per-sede arithmetic (prompt 110): each sede computes its OWN active-member count, not the org total.
        foreach ($locations as $location) {
            $this->assertGreaterThan(0, StockCeiling::forLocation($location)['active_members']);
        }

        // Deliberate, labelled demo: the small curated member base means the compliance ceiling fires by
        // design — documenting the overage is intentional, not the old org-wide-count bug returning.
        $this->assertTrue(
            $locations->contains(fn (Location $l): bool => StockCeiling::forLocation($l)['exceeded']),
            'The demo seed is expected to exceed the ceiling at a sede to demonstrate the warning.'
        );
    }

    public function test_an_english_locale_seed_produces_english_data(): void
    {
        $this->seedDemo('en');

        $this->assertEqualsCanonicalizing(['Central Branch', 'North Branch'], Location::pluck('name')->all());
        $this->assertEqualsCanonicalizing(['Member', 'Therapeutic'], MembershipTier::pluck('name')->all());
        $this->assertTrue(ExpenseCategory::where('name', 'Consumables')->exists());
        $this->assertFalse(ExpenseCategory::where('name', 'Consumibles')->exists());
        // Generated names track the English faker locale the seeder selected (proven deterministically
        // by the locale it set — random names can't be asserted individually).
        $this->assertSame('en_GB', config('app.faker_locale'));
        $this->assertGreaterThan(20, Member::count());
    }

    public function test_a_spanish_locale_seed_produces_spanish_data(): void
    {
        $this->seedDemo('es');

        $this->assertEqualsCanonicalizing(['Sede Centro', 'Sede Norte'], Location::pluck('name')->all());
        $this->assertEqualsCanonicalizing(['Socio', 'Terapéutico'], MembershipTier::pluck('name')->all());
        $this->assertTrue(ExpenseCategory::where('name', 'Consumibles')->exists());
        $this->assertFalse(ExpenseCategory::where('name', 'Consumables')->exists());
        $this->assertSame('es_ES', config('app.faker_locale'));
    }

    public function test_the_demo_profile_writes_settings_rows_and_leaves_defaults_untouched(): void
    {
        $this->seedDemo('en');

        // The profile is present as rows Settings::get() returns.
        $this->assertTrue((bool) Settings::get('wallet_debt_allowed'));
        $this->assertSame(5000, (int) Settings::get('wallet_debt_limit_cents'));
        $this->assertSame(3000, (int) Settings::get('wallet_door_debt_threshold_cents'));
        $this->assertTrue((bool) Settings::get('temporary_members_enabled'));
        $this->assertTrue((bool) Settings::get('camera_scan_enabled'));
        $this->assertTrue((bool) Settings::get('signature_on_dispensation'));

        // Production DEFAULTS are the conservative posture and must be provably unmodified — this fails
        // the moment a default is flipped in code instead of the profile writing a row.
        $this->assertFalse(Settings::DEFAULTS['wallet_debt_allowed']);
        $this->assertFalse(Settings::DEFAULTS['temporary_members_enabled']);
        $this->assertFalse(Settings::DEFAULTS['camera_scan_enabled']);
        $this->assertFalse(Settings::DEFAULTS['signature_on_dispensation']);
        $this->assertSame(0, Settings::DEFAULTS['wallet_debt_limit_cents']);
    }

    public function test_every_enabled_feature_has_supporting_data(): void
    {
        $this->seedDemo('en');
        $loc = Location::query()->orderBy('name')->firstOrFail()->id;

        // Wallet debt on → a member in debt within the cap AND one near it.
        $debts = Member::all()
            ->map(fn (Member $m): int => Wallet::balance($m->id, $loc))
            ->filter(fn (int $b): bool => $b < 0)
            ->sort()
            ->values();
        $this->assertGreaterThanOrEqual(2, $debts->count());
        $this->assertTrue($debts->every(fn (int $b): bool => $b >= -5000)); // all within the €50 cap
        $this->assertTrue($debts->contains(fn (int $b): bool => $b < -3000)); // at least one past the door threshold

        // Temporary members on → a temporary member approaching expiry.
        $temp = Member::where('kind', MemberKind::TEMPORARY)->firstOrFail();
        $this->assertNotNull($temp->temporary_expires_at);
        $this->assertTrue($temp->temporary_expires_at->isFuture());

        // Prompt 51 tabs aren't empty: at least one sanction exists.
        $this->assertGreaterThanOrEqual(1, MemberSanction::count());
    }

    public function test_a_seeded_member_can_be_dispensed_to_with_no_unpaid_fee_block(): void
    {
        $this->seedDemo('en');
        $location = Location::query()->orderBy('name')->firstOrFail();
        app(ActiveScope::class)->setLocation($location->id); // seeding left the scope on the last location

        // A plain active member: fee paid, no debt, carencia passed (the feature debtors/carencia member
        // are excluded by these filters).
        $member = Member::where('status', MemberStatus::ACTIVE)->get()->first(function (Member $m) use ($location): bool {
            return Wallet::balance($m->id, $location->id) >= 0
                && $m->carencia_ends_at?->isPast()
                && $m->memberships()->where('location_id', $location->id)->where('status', 'ACTIVE')->exists()
                // …who hasn't already consumed toward today's limit during the seeded fortnight (else the
                // extra 100 cg could breach the daily cap — a limit concern, not the fee block under test).
                && $m->dispensations()->whereDate('dispensed_at', now())->doesntExist();
        });
        $this->assertNotNull($member, 'the seed must contain a clean, dispensable active member');

        // The exact block prompt 46 was built to resolve — must be satisfied, not blocking.
        $verdict = (new ResolveMemberEligibility)->handle($member, $location, 'counter');
        $feeRule = collect($verdict->rules)->firstWhere('rule', 'unpaid_fee');
        $this->assertTrue($feeRule['satisfied'], 'a freshly seeded member must not trip the unpaid_fee block');

        // End-to-end teeth: a live dispensation actually commits.
        $batch = Batch::where('location_id', $location->id)->where('remaining_cg', '>', 500)->firstOrFail();
        $dispensation = (new CommitDispensation)->handle($member, $location, [
            ['genetic_id' => $batch->genetic_id, 'batch_id' => $batch->id, 'grams_cg' => 100],
        ]);
        $this->assertSame(DispensationStatus::COMPLETED, $dispensation->status);
    }

    public function test_the_bar_and_financial_reports_reconcile_non_zero_after_seeding(): void
    {
        $this->seedDemo('en');
        $org = Organisation::firstOrFail()->id;
        $locationIds = Location::pluck('id')->all();

        $barSales = (new BarSalesReport($org, $locationIds, Period::thisMonth()))->primary();
        $barra = (new FinancialReport($org, $locationIds, Period::thisMonth()))->primary()->totals['barra'];

        $this->assertGreaterThan(0, $barSales->totals['importe']);   // the fixture-drift guard, with teeth
        $this->assertSame($barra, $barSales->totals['importe']);     // itemised reconciles with the aggregate
    }
}
