<?php

namespace Tests\Feature\Reports;

use App\Actions\Attendance\ResolveMemberEligibility;
use App\Actions\Memberships\RecordFeePayment;
use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\FeePaymentMethod;
use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Enums\WalletTransactionType;
use App\Filament\Pages\Reports\DebtorReportPage;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Period;
use App\Support\Settings;
use App\ViewModels\Reports\DebtorReport;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Prompt 82 — who owes the club money, wallet debt and unpaid cuota kept DISTINCT and never merged. The
 * report's idea of "in debt / over the counter threshold" is asserted against ResolveMemberEligibility so
 * the two cannot diverge, and the query count is asserted not to scale with the membership.
 */
class DebtorReportTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function member(int $feeCents = 2000, ?Location $at = null): Membership
    {
        $at ??= $this->location;
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);

        return Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $at->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => $feeCents,
            'starts_at' => now()->subMonths(2),
        ]);
    }

    private function wallet(Membership $m, int $cents): void
    {
        (new RecordWalletTransaction)->handle($m->member, $m->location, $cents, WalletTransactionType::ADJUSTMENT, ['allow_debt' => true]);
    }

    private function report(?array $locationIds = null): DebtorReport
    {
        return new DebtorReport($this->org->id, $locationIds ?? [$this->location->id], Period::thisMonth());
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(DebtorReport $report): array
    {
        return collect($report->primary()->rows)->keyBy('member_no')->all();
    }

    public function test_a_wallet_debtor_appears_and_a_member_in_credit_does_not(): void
    {
        $debtor = $this->member(0);
        $this->wallet($debtor, -1000); // €10 debt
        $credit = $this->member(0);
        $this->wallet($credit, 500);   // in credit

        $rows = $this->rows($this->report());
        $this->assertSame(1000, $rows[$debtor->member->member_no]['monedero']);
        $this->assertArrayNotHasKey($credit->member->member_no, $rows);
    }

    public function test_an_unpaid_cuota_appears_and_a_fully_paid_one_does_not(): void
    {
        $unpaid = $this->member(2000); // €20 fee, nothing paid
        $paid = $this->member(2000);
        (new RecordFeePayment)->handle($paid, 2000, FeePaymentMethod::CASH);

        $rows = $this->rows($this->report());
        $this->assertSame(2000, $rows[$unpaid->member->member_no]['cuota']);
        $this->assertArrayNotHasKey($paid->member->member_no, $rows);
    }

    public function test_a_member_with_both_debts_appears_once_and_is_not_double_counted(): void
    {
        $m = $this->member(2000); // €20 cuota unpaid
        $this->wallet($m, -1500);  // €15 wallet debt

        $report = $this->report();
        $rows = $this->rows($report);
        $this->assertCount(1, array_filter($report->primary()->rows, fn ($r): bool => $r['member_no'] === $m->member->member_no));
        $this->assertSame(1500, $rows[$m->member->member_no]['monedero']);
        $this->assertSame(2000, $rows[$m->member->member_no]['cuota']);
        // The two obligations stay in separate totals — never merged.
        $this->assertSame(1500, $report->primary()->totals['monedero']);
        $this->assertSame(2000, $report->primary()->totals['cuota']);
    }

    public function test_the_over_threshold_flag_matches_the_eligibility_resolver(): void
    {
        Settings::set('wallet_debt_limit_cents', '500', SettingType::INT); // €5 counter cap
        $over = $this->member(0);
        $this->wallet($over, -800); // beyond the cap
        $under = $this->member(0);
        $this->wallet($under, -300); // within the cap

        $rows = $this->rows($this->report());

        foreach ([$over, $under] as $m) {
            $verdict = (new ResolveMemberEligibility)->handle($m->member, $this->location, 'counter');
            $debtRuleSatisfied = collect($verdict->rules)->firstWhere('rule', 'debt')['satisfied'];
            $reportSaysBlocked = ($rows[$m->member->member_no]['bloqueado'] ?? null) === __('Sí');
            // The report flags "blocked" exactly when the resolver's debt rule is NOT satisfied. One definition.
            $this->assertSame(! $debtRuleSatisfied, $reportSaysBlocked, "mismatch for {$m->member->member_no}");
        }
    }

    public function test_the_query_count_does_not_scale_with_member_count(): void
    {
        for ($i = 0; $i < 2; $i++) {
            $this->wallet($this->member(0), -100);
        }
        // Flush the settings memo before each measurement so both are COLD renders (the first render in a
        // request). Otherwise the first render pays a one-time setting query the second gets for free (prompt
        // 109), and the two would differ by that constant — which is not the member-count scaling this guards.
        Settings::flush();
        $small = $this->countQueries(fn () => $this->report()->tables());

        for ($i = 0; $i < 12; $i++) {
            $this->wallet($this->member(0), -100);
        }
        Settings::flush();
        $large = $this->countQueries(fn () => $this->report()->tables());

        $this->assertSame($small, $large, 'The debtor report must aggregate in SQL, not per member.');
    }

    public function test_the_report_is_location_scoped_and_gated(): void
    {
        $here = $this->member(0);
        $this->wallet($here, -1000);
        $other = Location::factory()->create(['organisation_id' => $this->org->id]);
        $elsewhere = $this->member(0, $other);
        $this->wallet($elsewhere, -1000);

        // Scoped to THIS sede only: the other sede's debtor is excluded.
        $rows = $this->rows($this->report([$this->location->id]));
        $this->assertArrayHasKey($here->member->member_no, $rows);
        $this->assertArrayNotHasKey($elsewhere->member->member_no, $rows);

        // Page gate: reports.view is required; a bare user without it is refused.
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value); // STAFF holds pos.use, not reports.view
        $this->actingAs($staff);
        $this->assertFalse(DebtorReportPage::canAccess());

        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value); // holds reports.view
        $this->actingAs($manager);
        $this->assertTrue(DebtorReportPage::canAccess());
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
