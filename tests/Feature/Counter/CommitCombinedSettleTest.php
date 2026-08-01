<?php

namespace Tests\Feature\Counter;

use App\Actions\Counter\CommitCombinedSettle;
use App\Actions\Till\OpenTill;
use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\BatchStatus;
use App\Enums\DispensationStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Enums\WalletTransactionType;
use App\Exceptions\DebtLimitExceededException;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Article;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use App\Support\Wallet;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 118 — one visit, one payment, TWO records. CommitCombinedSettle commits a dispensation AND a bar
 * order for the same member atomically, on their separate ledgers, with the combined wallet draw gated up
 * front. These prove the properties the branch exists for.
 */
class CommitCombinedSettleTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $genetic;

    private Batch $batch;

    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id,
            'location_id' => $this->location->id, 'tier_id' => null,
            'price_per_gram_cents' => 1000, 'active' => true, // €10,00/g
        ]);
        $this->batch = Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id,
            'location_id' => $this->location->id, 'remaining_cg' => 100000, 'status' => BatchStatus::OPEN,
        ]);
        $this->article = Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'price_cents' => 250, 'stock' => 10, 'active' => true, // €2,50, 10 in stock
        ]);
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::MANAGER->value);
        $user->locations()->sync([$this->location->id]);

        return $user;
    }

    private function member(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(),
            'daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000,
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);

        return $member;
    }

    private function till(User $operator): TillSession
    {
        return (new OpenTill)->handle($this->location, 'POS-1', 10000, ['operator_id' => $operator->id]);
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>} */
    private function baskets(int $gramsCg = 350, int $qty = 2): array
    {
        return [
            [['genetic_id' => $this->genetic->id, 'batch_id' => $this->batch->id, 'grams_cg' => $gramsCg]],
            [['article_id' => $this->article->id, 'qty' => $qty]],
        ];
    }

    public function test_a_combined_settle_creates_one_dispensation_and_one_order_on_separate_ledgers(): void
    {
        $operator = $this->operator();
        $member = $this->member();
        $till = $this->till($operator);
        [$disp, $order] = $this->baskets(350, 2); // €35 cannabis + €5 bar

        $result = (new CommitCombinedSettle)->handle($member, $this->location, $disp, $order, [
            'till_session_id' => $till->id, 'operator_id' => $operator->id,
            'dispensation' => ['cash_cents' => 3500],
            'order' => ['cash_cents' => 500],
        ]);

        // Exactly one of each — never a merged row.
        $this->assertSame(1, Dispensation::query()->count());
        $this->assertSame(1, Order::query()->count());

        $this->assertInstanceOf(Dispensation::class, $result['dispensation']);
        $this->assertInstanceOf(Order::class, $result['order']);
        $this->assertSame(DispensationStatus::COMPLETED, $result['dispensation']->status);
        $this->assertSame(OrderStatus::COMPLETED, $result['order']->status);

        // Same member, same till — one visit.
        $this->assertSame($member->id, $result['dispensation']->member_id);
        $this->assertSame($member->id, $result['order']->member_id);
        $this->assertSame($till->id, $result['dispensation']->till_session_id);
        $this->assertSame($till->id, $result['order']->till_session_id);

        // Separate ledgers: the €35 aportación is on the dispensation, the €5 bar takings on the order.
        $this->assertSame(3500, $result['dispensation']->total_cents->cents);
        $this->assertSame(500, $result['order']->total_cents->cents);
    }

    public function test_the_settle_is_atomic_a_failing_order_rolls_back_the_dispensation(): void
    {
        $operator = $this->operator();
        $member = $this->member();
        $till = $this->till($operator);
        $stockBefore = $this->batch->refresh()->remaining_cg->centigrams;

        // Order asks for MORE than the article has — CommitOrder's single stock writer refuses to oversell.
        [$disp] = $this->baskets();
        $order = [['article_id' => $this->article->id, 'qty' => 999]];

        try {
            (new CommitCombinedSettle)->handle($member, $this->location, $disp, $order, [
                'till_session_id' => $till->id, 'operator_id' => $operator->id,
                'dispensation' => ['cash_cents' => 3500], 'order' => ['cash_cents' => 500],
            ]);
            $this->fail('Expected the oversell to abort the settle.');
        } catch (\Throwable) {
            // expected
        }

        // NEITHER record exists, and the dispensation's stock decrement was rolled back with it.
        $this->assertSame(0, Dispensation::query()->count());
        $this->assertSame(0, Order::query()->count());
        $this->assertSame($stockBefore, $this->batch->refresh()->remaining_cg->centigrams);
    }

    public function test_the_combined_wallet_draw_is_gated_up_front(): void
    {
        $operator = $this->operator();
        $member = $this->member(); // balance 0, debt not allowed by default
        $till = $this->till($operator);
        [$disp, $order] = $this->baskets();

        // Each half wallet-paid; combined €40 draw with €0 available must be refused before any write.
        try {
            (new CommitCombinedSettle)->handle($member, $this->location, $disp, $order, [
                'till_session_id' => $till->id, 'operator_id' => $operator->id,
                'dispensation' => ['wallet_cents' => 3500, 'cash_cents' => 0],
                'order' => ['wallet_cents' => 500, 'cash_cents' => 0],
            ]);
            $this->fail('Expected the combined wallet draw to be refused.');
        } catch (DebtLimitExceededException) {
            // expected
        }

        $this->assertSame(0, Dispensation::query()->count());
        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_combined_wallet_draw_within_the_balance_settles(): void
    {
        $operator = $this->operator();
        $member = $this->member();
        $till = $this->till($operator);
        // Top the wallet up to cover the whole visit.
        (new RecordWalletTransaction)->handle($member, $this->location, 5000, WalletTransactionType::TOPUP, ['operator_id' => $operator->id]);
        [$disp, $order] = $this->baskets();

        $result = (new CommitCombinedSettle)->handle($member, $this->location, $disp, $order, [
            'till_session_id' => $till->id, 'operator_id' => $operator->id,
            'dispensation' => ['wallet_cents' => 3500, 'cash_cents' => 0],
            'order' => ['wallet_cents' => 500, 'cash_cents' => 0],
        ]);

        $this->assertSame(4000, $result['dispensation']->wallet_cents->cents + $result['order']->wallet_cents->cents);
        $this->assertSame(1000, Wallet::balance($member->id, $this->location->id)); // 5000 − 4000
    }

    public function test_bar_items_never_count_toward_the_gram_cap(): void
    {
        // A daily gram cap exactly at the dispensation weight. The bar order must not push the visit over it.
        Settings::set('daily_limit_cg', 350, SettingType::INT, $this->location->id);
        $operator = $this->operator();
        $member = $this->member();
        $till = $this->till($operator);
        [$disp, $order] = $this->baskets(350, 3); // dispensation exactly at the cap; 3 bar items

        $result = (new CommitCombinedSettle)->handle($member, $this->location, $disp, $order, [
            'till_session_id' => $till->id, 'operator_id' => $operator->id,
            'dispensation' => ['cash_cents' => 3500], 'order' => ['cash_cents' => 750],
        ]);

        // The settle succeeds: the gram cap only ever saw the 350 cg of genetics, never the bar articles.
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(350, (int) $result['dispensation']->lines->sum(fn ($line): int => $line->grams_cg->centigrams));
    }

    public function test_the_dispensary_pos_settles_a_combined_visit_and_offers_both_receipts(): void
    {
        // The reachable entry point: the counter builds a dispensation basket, adds a bar item, and settles the
        // whole visit once — landing two receipts (a dispensation and an order).
        $operator = $this->operator();
        $this->actingAs($operator);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($operator);
        $this->till($operator);
        $member = $this->member();

        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('chooseGenetic', $this->genetic->id)
            ->set('weightInput', '1')
            ->call('addLine')
            ->call('addBarItem', $this->article->id)
            ->set('cashTendered', '20')
            ->call('settleWithBar')
            ->assertSet('lastDispensationId', fn ($v): bool => $v !== null)
            ->assertSet('lastOrderId', fn ($v): bool => $v !== null)
            ->assertSet('barBasket', []); // cleared after a clean settle

        $this->assertSame(1, Dispensation::query()->count());
        $this->assertSame(1, Order::query()->count());
    }
}
