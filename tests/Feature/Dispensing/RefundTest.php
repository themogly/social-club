<?php

namespace Tests\Feature\Dispensing;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Dispensing\RefundDispensation;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\DispensationStatus;
use App\Enums\MembershipStatus;
use App\Enums\RefundDestination;
use App\Enums\RefundMethod;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Enums\StockMovementType;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\CashMovement;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\Refund;
use App\Models\StockMovement;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Money;
use App\Support\Period;
use App\Support\Settings;
use App\Support\TillSummary;
use App\Support\Wallet;
use App\ViewModels\Reports\ConsumptionReport;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 65 — a partial, reversible refund that returns money (wallet or cash-from-the-till) and product
 * (sellable → batch, unsellable → MERMA), never mutating the original dispensation and never over-refunding.
 * Money is asserted in real stored cents; weight in real centigrams.
 */
class RefundTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $genetic;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->location->id,
            'tier_id' => null, 'price_per_gram_cents' => 1000, 'active' => true, // €10/g
        ]);
        $this->batch = Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->location->id,
            'remaining_cg' => 100000, 'status' => BatchStatus::OPEN,
        ]);
    }

    private function member(): Member
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'carencia_ends_at' => now()->subDay()]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
        ]);

        return $member;
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::MANAGER->value); // dispensation.void + stock.merma
        // The refund policy binds the actor to the row's sede (prompt 75) — assign the one they work.
        $user->locations()->sync([$this->location->id]);

        return $user;
    }

    private function till(): TillSession
    {
        return (new OpenTill)->handle($this->location, 'TILL-01', 20000); // €200 float
    }

    /** 100 cg (1 g) → €10.00, paid cash. */
    private function dispense(?Member $member = null, int $gramsCg = 100): Dispensation
    {
        return (new CommitDispensation)->handle(
            $member ?? $this->member(), $this->location,
            [['genetic_id' => $this->genetic->id, 'batch_id' => $this->batch->id, 'grams_cg' => $gramsCg]],
            [],
        );
    }

    public function test_a_partial_cash_refund_returns_money_via_the_till_and_product_to_stock(): void
    {
        $d = $this->dispense();
        $till = $this->till();
        $before = TillSummary::expectedCents($till);

        $refund = (new RefundDispensation)->handle($d, $this->manager(), [
            'amount_cents' => 400, 'grams_cg' => 40, 'destination' => RefundDestination::STOCK,
            'method' => RefundMethod::CASH, 'reason' => 'Producto mohoso', 'till_session' => $till,
        ]);

        $this->assertSame(400, $refund->amount_cents->cents);
        $this->assertSame(40, $refund->grams_cg->centigrams);
        $this->assertSame(RefundDestination::STOCK, $refund->destination);
        $this->assertSame($till->id, $refund->till_session_id);

        // Product back to the batch: 100000 − 100 (dispensed) + 40 (refunded) = 99940.
        $this->assertSame(99940, $this->batch->refresh()->remaining_cg->centigrams);

        // Cash OUT of the drawer — the arqueo expected figure drops by exactly the refund.
        $out = CashMovement::query()->withoutGlobalScopes()->where('till_session_id', $till->id)->where('type', 'OUT')->firstOrFail();
        $this->assertSame(-400, $out->amount_cents->cents);
        $this->assertSame($before - 400, TillSummary::expectedCents($till->refresh()));

        // The original is never mutated.
        $this->assertSame(DispensationStatus::COMPLETED, $d->refresh()->status);
        $this->assertSame(1000, $d->total_cents->cents);
    }

    public function test_a_wallet_refund_credits_the_member_and_touches_no_till(): void
    {
        $member = $this->member();
        $d = $this->dispense($member);

        (new RefundDispensation)->handle($d, $this->manager(), [
            'amount_cents' => 400, 'grams_cg' => 40, 'destination' => RefundDestination::STOCK,
            'method' => RefundMethod::WALLET, 'reason' => 'Devolución parcial',
        ]);

        $this->assertSame(400, Wallet::balance($member->id, $this->location->id));
        $this->assertSame(0, CashMovement::query()->withoutGlobalScopes()->count());
    }

    public function test_an_unsellable_refund_writes_merma_and_does_not_increase_sellable_stock(): void
    {
        $d = $this->dispense();

        (new RefundDispensation)->handle($d, $this->manager(), [
            'amount_cents' => 400, 'grams_cg' => 40, 'destination' => RefundDestination::MERMA,
            'method' => RefundMethod::WALLET, 'reason' => 'Devuelto pero no vendible',
        ]);

        // Returned (+40) then written off (−40): net remaining_cg unchanged from post-dispense (99900).
        $this->assertSame(99900, $this->batch->refresh()->remaining_cg->centigrams);
        $this->assertSame(40, (int) StockMovement::query()->withoutGlobalScopes()->where('type', StockMovementType::ADJUSTMENT->value)->sum('qty_cg'));
        $this->assertSame(-40, (int) StockMovement::query()->withoutGlobalScopes()->where('type', StockMovementType::MERMA->value)->sum('qty_cg'));
    }

    public function test_it_refuses_to_over_refund_the_amount(): void
    {
        $d = $this->dispense(); // €10.00
        $manager = $this->manager();

        (new RefundDispensation)->handle($d, $manager, [
            'amount_cents' => 600, 'grams_cg' => 0, 'destination' => RefundDestination::STOCK,
            'method' => RefundMethod::WALLET, 'reason' => 'Parte 1',
        ]);

        $this->expectException(RuntimeException::class);
        (new RefundDispensation)->handle($d, $manager, [
            'amount_cents' => 500, 'grams_cg' => 0, 'destination' => RefundDestination::STOCK,
            'method' => RefundMethod::WALLET, 'reason' => 'Parte 2', // 600 + 500 > 1000
        ]);
    }

    public function test_it_refuses_to_over_refund_the_weight(): void
    {
        $d = $this->dispense(); // 100 cg
        $manager = $this->manager();

        (new RefundDispensation)->handle($d, $manager, [
            'amount_cents' => 100, 'grams_cg' => 60, 'destination' => RefundDestination::STOCK,
            'method' => RefundMethod::WALLET, 'reason' => 'Parte 1',
        ]);

        $this->expectException(RuntimeException::class);
        (new RefundDispensation)->handle($d, $manager, [
            'amount_cents' => 100, 'grams_cg' => 50, 'destination' => RefundDestination::STOCK,
            'method' => RefundMethod::WALLET, 'reason' => 'Parte 2', // 60 + 50 > 100
        ]);
    }

    public function test_repeated_partial_refunds_never_exceed_the_original(): void
    {
        // The cumulative guard is read INSIDE a lockForUpdate on the header, so two genuinely concurrent
        // partials serialise and the second sees the first's committed row (verified against MySQL in CI —
        // SQLite has no row locks). This asserts the correctness property the lock guarantees: the third
        // partial, which would push cumulative over the original, is refused.
        $d = $this->dispense(); // €10.00 / 100 cg
        $manager = $this->manager();

        foreach (['A', 'B'] as $part) {
            (new RefundDispensation)->handle($d, $manager, [
                'amount_cents' => 400, 'grams_cg' => 40, 'destination' => RefundDestination::STOCK,
                'method' => RefundMethod::WALLET, 'reason' => "Parte {$part}",
            ]); // cumulative 800 / 80 cg — OK
        }

        $this->expectException(RuntimeException::class);
        (new RefundDispensation)->handle($d, $manager, [
            'amount_cents' => 400, 'grams_cg' => 40, 'destination' => RefundDestination::STOCK,
            'method' => RefundMethod::WALLET, 'reason' => 'Parte C', // 1200 / 120 cg — exceeds both
        ]);
    }

    public function test_a_cash_refund_without_an_open_till_is_refused(): void
    {
        $d = $this->dispense();

        $this->expectException(RuntimeException::class);
        (new RefundDispensation)->handle($d, $this->manager(), [
            'amount_cents' => 400, 'grams_cg' => 0, 'destination' => RefundDestination::STOCK,
            'method' => RefundMethod::CASH, 'reason' => 'Sin caja', // no till_session
        ]);
    }

    public function test_a_refund_without_the_permission_is_denied(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value); // no dispensation.void
        $d = $this->dispense();

        $this->expectException(AuthorizationException::class);
        (new RefundDispensation)->handle($d, $staff, [
            'amount_cents' => 400, 'grams_cg' => 0, 'destination' => RefundDestination::STOCK,
            'method' => RefundMethod::WALLET, 'reason' => 'Intento',
        ]);
    }

    public function test_a_refund_without_a_reason_is_refused(): void
    {
        $d = $this->dispense();

        $this->expectException(RuntimeException::class);
        (new RefundDispensation)->handle($d, $this->manager(), [
            'amount_cents' => 400, 'grams_cg' => 0, 'destination' => RefundDestination::STOCK,
            'method' => RefundMethod::WALLET, 'reason' => '   ',
        ]);
    }

    public function test_the_refund_is_audited_with_amount_weight_destination_and_cumulative(): void
    {
        $manager = $this->manager();
        $d = $this->dispense();

        (new RefundDispensation)->handle($d, $manager, [
            'amount_cents' => 400, 'grams_cg' => 40, 'destination' => RefundDestination::MERMA,
            'method' => RefundMethod::WALLET, 'reason' => 'Mohoso',
        ]);

        $audit = AuditLog::query()->where('action', 'dispensation.refunded')->latest()->firstOrFail();
        $this->assertSame(0, $audit->before['refunded_amount_cents']);
        $this->assertSame(400, $audit->after['amount_cents']);
        $this->assertSame(40, $audit->after['grams_cg']);
        $this->assertSame('MERMA', $audit->after['destination']);
        $this->assertSame('WALLET', $audit->after['method']);
        $this->assertSame(400, $audit->after['cumulative_amount_cents']);
        $this->assertSame($manager->id, $audit->after['refunded_by']);
    }

    public function test_a_dispensation_older_than_the_window_cannot_be_refunded(): void
    {
        Settings::set('refund_window_days', 30, SettingType::INT);
        $d = $this->dispense();
        $d->forceFill(['dispensed_at' => now()->subDays(40)])->save();

        $this->expectException(RuntimeException::class);
        (new RefundDispensation)->handle($d, $this->manager(), [
            'amount_cents' => 400, 'grams_cg' => 0, 'destination' => RefundDestination::STOCK,
            'method' => RefundMethod::WALLET, 'reason' => 'Fuera de plazo',
        ]);
    }

    public function test_the_report_totals_refunds_for_the_period(): void
    {
        $d = $this->dispense();
        (new RefundDispensation)->handle($d, $this->manager(), [
            'amount_cents' => 400, 'grams_cg' => 0, 'destination' => RefundDestination::STOCK,
            'method' => RefundMethod::WALLET, 'reason' => 'x',
        ]);

        $summary = (new ConsumptionReport($this->org->id, [$this->location->id], Period::thisMonth()))->summary();
        $byLabel = array_column($summary, 'value', 'label');

        $this->assertSame(Money::fromCents(400)->formatted(), $byLabel[__('Reembolsos')]);
    }
}
