<?php

namespace Tests\Feature\Dashboard;

use App\Enums\BatchStatus;
use App\Enums\DispensationStatus;
use App\Enums\MembershipStatus;
use App\Enums\OrderStatus;
use App\Enums\TillSessionStatus;
use App\Enums\WalletTransactionType;
use App\Models\Batch;
use App\Models\CheckIn;
use App\Models\Dispensation;
use App\Models\DispensationLine;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\WalletTransaction;
use App\Support\ActiveScope;
use App\Support\Period;
use App\ViewModels\Dashboard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardDataTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $a;

    private Location $b;

    private Genetic $genetic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-07-15 12:00:00'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->a = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->b = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function dispense(Location $location, int $total, int $cash, int $wallet, int $gramsCg, ?string $at = null): Dispensation
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        $d = Dispensation::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $location->id, 'member_id' => $member->id,
            'status' => DispensationStatus::COMPLETED, 'total_cents' => $total, 'cash_cents' => $cash,
            'wallet_cents' => $wallet, 'dispensed_at' => $at ?? now(),
        ]);
        DispensationLine::factory()->create([
            'dispensation_id' => $d->id, 'genetic_id' => $this->genetic->id, 'grams_cg' => $gramsCg,
        ]);

        return $d;
    }

    private function dash(?array $locationIds, ?Period $period = null, bool $finance = true): Dashboard
    {
        return new Dashboard($this->org->id, $locationIds, $period ?? Period::today(), canSeeFinance: $finance);
    }

    public function test_each_stat_figure_equals_its_control_query_for_the_scoped_period(): void
    {
        $this->dispense($this->a, 3500, 2000, 1500, 350);            // today, A
        $this->dispense($this->b, 5000, 5000, 0, 700);               // today, B (must be excluded from A)
        Order::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->a->id,
            'status' => OrderStatus::COMPLETED, 'total_cents' => 1000, 'cash_cents' => 1000, 'wallet_cents' => 0,
        ]);

        $dash = $this->dash([$this->a->id]);

        // Contributions = Σ total_cents of A's completed dispensations today.
        $this->assertSame(
            (int) Dispensation::query()->withoutGlobalScopes()->where('location_id', $this->a->id)
                ->where('status', DispensationStatus::COMPLETED->value)->sum('total_cents'),
            $dash->contributionsCents(),
        );
        $this->assertSame(3500, $dash->contributionsCents());
        $this->assertSame(['cash' => 2000, 'wallet' => 1500], $dash->contributionSplit());
        $this->assertSame(350, $dash->gramsDispensedCg());
        $this->assertSame(2, $dash->transactionCount());            // 1 dispensation + 1 order
        $this->assertSame(3500, $dash->averageContributionCents()); // 3500 over the single dispensation
    }

    public function test_average_contribution_is_per_dispensation(): void
    {
        $this->dispense($this->a, 4000, 4000, 0, 400);
        $this->dispense($this->a, 2000, 2000, 0, 200);
        $dash = $this->dash([$this->a->id]);

        $this->assertSame(6000, $dash->contributionsCents());
        $this->assertSame(3000, $dash->averageContributionCents()); // 6000 / 2
    }

    public function test_stock_value_and_wallet_float_and_debt_are_scoped(): void
    {
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->a->id,
            'remaining_cg' => 10000, 'cost_per_gram_cents' => 500, 'status' => BatchStatus::OPEN, // 100g × €5 = €500
        ]);
        $credit = Member::factory()->create(['organisation_id' => $this->org->id]);
        $debtor = Member::factory()->create(['organisation_id' => $this->org->id]);
        $this->walletMove($credit, $this->a, 5000, WalletTransactionType::TOPUP);
        $this->walletMove($debtor, $this->a, -2000, WalletTransactionType::PURCHASE);

        $dash = $this->dash([$this->a->id]);
        $this->assertSame(50000, $dash->stockValueCents()); // 10000cg × 500 ÷ 100
        $this->assertSame(5000, $dash->walletFloatCents());
        $this->assertSame(2000, $dash->walletDebtCents());
    }

    public function test_inside_now_counts_open_check_ins_in_scope(): void
    {
        $this->openCheckIn($this->a);
        $this->openCheckIn($this->a);
        $this->openCheckIn($this->b);

        $this->assertSame(2, $this->dash([$this->a->id])->insideNow());
        $this->assertSame(3, $this->dash(null)->insideNow()); // org-wide
    }

    public function test_the_period_toggle_changes_the_figures(): void
    {
        $this->dispense($this->a, 3500, 3500, 0, 350);                                   // today
        $this->dispense($this->a, 9000, 9000, 0, 900, '2026-07-02 10:00:00');            // earlier this month, not today

        $this->assertSame(3500, $this->dash([$this->a->id], Period::today())->contributionsCents());
        $this->assertSame(12500, $this->dash([$this->a->id], Period::thisMonth())->contributionsCents());
    }

    public function test_location_scoping_excludes_other_locations_and_rollup_sums_both(): void
    {
        $this->dispense($this->a, 3500, 3500, 0, 350);
        $this->dispense($this->b, 5000, 5000, 0, 500);

        $this->assertSame(3500, $this->dash([$this->a->id])->contributionsCents());  // A only
        $this->assertSame(5000, $this->dash([$this->b->id])->contributionsCents());  // B only
        $this->assertSame(8500, $this->dash(null)->contributionsCents());            // owner "All"
    }

    public function test_staff_payload_withholds_finance_figures(): void
    {
        $this->dispense($this->a, 3500, 3500, 0, 350);

        $staff = $this->dash([$this->a->id], finance: false)->toArray();
        $owner = $this->dash([$this->a->id], finance: true)->toArray();

        $this->assertArrayNotHasKey('contributions_cents', $staff);
        $this->assertArrayNotHasKey('wallet_float_cents', $staff);
        $this->assertArrayHasKey('inside_now', $staff);          // operational figures stay
        $this->assertArrayHasKey('contributions_cents', $owner);
    }

    public function test_alerts_fire_on_the_right_conditions_with_the_right_severity(): void
    {
        // Over-limit member: 100 g monthly cap, 120 g dispensed this month.
        $overLimit = Member::factory()->create(['organisation_id' => $this->org->id, 'monthly_limit_cg' => 10000]);
        $d = Dispensation::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->a->id, 'member_id' => $overLimit->id,
            'status' => DispensationStatus::COMPLETED, 'total_cents' => 0, 'cash_cents' => 0, 'wallet_cents' => 0,
            'dispensed_at' => now(),
        ]);
        DispensationLine::factory()->create(['dispensation_id' => $d->id, 'genetic_id' => $this->genetic->id, 'grams_cg' => 12000]);

        // Unreconciled (still open) till.
        TillSession::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->a->id, 'status' => TillSessionStatus::OPEN]);

        // Expiring batch + a huge batch to breach the on-site stock ceiling.
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->a->id,
            'remaining_cg' => 5000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addDays(5)->toDateString(),
        ]);
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->a->id,
            'remaining_cg' => 100_000_000, 'status' => BatchStatus::OPEN, // 1 tonne — well past any ceiling
        ]);

        $alerts = collect($this->dash([$this->a->id])->alerts())->keyBy('key');

        $this->assertSame('warning', $alerts['members_over_limit']['severity']);
        $this->assertSame('warning', $alerts['unreconciled_till']['severity']);
        $this->assertSame('warning', $alerts['batches_expiring']['severity']);
        $this->assertSame('error', $alerts['stock_ceiling_exceeded']['severity']);
    }

    private function walletMove(Member $member, Location $location, int $amount, WalletTransactionType $type): void
    {
        WalletTransaction::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $location->id,
            'amount_cents' => $amount, 'type' => $type, 'balance_after_cents' => $amount,
        ]);
    }

    private function openCheckIn(Location $location): void
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
        ]);
        CheckIn::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $location->id,
            'checked_in_at' => now(), 'checked_out_at' => null,
        ]);
    }
}
