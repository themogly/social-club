<?php

namespace Tests\Feature\Bar;

use App\Actions\Bar\CommitOrder;
use App\Actions\Bar\VoidOrder;
use App\Actions\Till\OpenTill;
use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Enums\WalletTransactionType;
use App\Models\Article;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\TillSummary;
use App\Support\Wallet;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class BarPosTest extends TestCase
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

    private function article(int $priceCents, int $stock): Article
    {
        return Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'price_cents' => $priceCents, 'stock' => $stock, 'active' => true,
        ]);
    }

    private function member(): Member
    {
        return Member::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::MANAGER->value); // has order.void

        return $user;
    }

    /**
     * @param  list<array<string,mixed>>  $lines
     * @param  array<string,mixed>  $options
     */
    private function commit(array $lines, array $options = []): Order
    {
        return (new CommitOrder)->handle($this->location, $lines, $options);
    }

    public function test_a_basket_of_three_articles_totals_depletes_stock_and_writes_one_order(): void
    {
        $a = $this->article(250, 10); // €2,50
        $b = $this->article(150, 5);  // €1,50
        $c = $this->article(400, 3);  // €4,00

        $order = $this->commit([
            ['article_id' => $a->id, 'qty' => 2], // 500
            ['article_id' => $b->id, 'qty' => 1], // 150
            ['article_id' => $c->id, 'qty' => 3], // 1200
        ]);

        $this->assertSame(1850, $order->total_cents->cents);
        $this->assertSame(8, $a->fresh()->stock);
        $this->assertSame(4, $b->fresh()->stock);
        $this->assertSame(0, $c->fresh()->stock);
        $this->assertSame(1, Order::query()->withoutGlobalScopes()->count());
        $this->assertSame(3, StockMovement::query()->withoutGlobalScopes()
            ->where('type', StockMovementType::SALE->value)->count());
    }

    public function test_a_genetic_can_never_be_added_to_a_bar_order(): void
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);

        // A genetic id does not resolve as an Article at this location → refused.
        $this->expectException(ModelNotFoundException::class);
        $this->commit([['article_id' => $genetic->id, 'qty' => 1]]);
    }

    public function test_member_less_order_succeeds_with_a_reference_but_wallet_requires_a_member(): void
    {
        $a = $this->article(500, 5);

        $order = $this->commit([['article_id' => $a->id, 'qty' => 1]], ['reference' => 'Invitado']);
        $this->assertNull($order->member_id);
        $this->assertSame('Invitado', $order->reference);

        try {
            $this->commit([['article_id' => $a->id, 'qty' => 1]], ['wallet_cents' => 500, 'cash_cents' => 0]);
            $this->fail('A wallet payment without a member should be refused.');
        } catch (RuntimeException) {
            // expected
        }
    }

    public function test_a_miscellaneous_line_needs_a_reference_and_moves_no_stock(): void
    {
        // Without a reference → refused.
        try {
            $this->commit([['description' => 'Propina', 'unit_price_cents' => 200, 'qty' => 1]]);
            $this->fail('A miscellaneous line without a reference should be refused.');
        } catch (RuntimeException) {
            // expected
        }

        // With a reference → priced, no stock row.
        $order = $this->commit([['description' => 'Donativo', 'unit_price_cents' => 350, 'qty' => 2, 'reference' => 'Evento']]);
        $this->assertSame(700, $order->total_cents->cents);
        $this->assertSame('Donativo', $order->items[0]['name']);
        $this->assertNull($order->items[0]['article_id']);
        $this->assertSame(0, StockMovement::query()->withoutGlobalScopes()->count());
    }

    public function test_void_returns_unit_stock_and_reverses_both_wallet_and_cash(): void
    {
        $manager = $this->manager();
        $member = $this->member();
        (new RecordWalletTransaction)->handle($member, $this->location, 5000, WalletTransactionType::TOPUP, ['allow_debt' => true]);
        $till = (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $a = $this->article(500, 10);

        // 2 × €5,00 = €10,00; €5,00 cash + €5,00 wallet.
        $order = $this->commit([['article_id' => $a->id, 'qty' => 2]], [
            'member_id' => $member->id, 'till_session_id' => $till->id,
            'cash_cents' => 500, 'wallet_cents' => 500, 'operator_id' => $manager->id,
        ]);

        $this->assertSame(1000, $order->total_cents->cents);
        $this->assertSame(8, $a->fresh()->stock);
        $this->assertSame(4500, Wallet::balance($member->id, $this->location->id)); // 5000 − 500
        $this->assertSame(10500, TillSummary::expectedCents($till));                 // 10000 float + 500 bar cash

        (new VoidOrder)->handle($order, $manager, 'Pedido equivocado');

        $this->assertSame(OrderStatus::VOIDED, $order->fresh()->status);
        $this->assertSame(10, $a->fresh()->stock);                                   // units returned
        $this->assertSame(5000, Wallet::balance($member->id, $this->location->id));  // wallet refunded
        $this->assertSame(10000, TillSummary::expectedCents($till));                 // bar cash dropped out
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.voided']);
    }

    public function test_bar_cash_is_separate_from_contribution_cash_in_the_drawer(): void
    {
        $till = (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $a = $this->article(300, 10);

        $this->commit([['article_id' => $a->id, 'qty' => 1]], ['till_session_id' => $till->id]);

        $breakdown = TillSummary::breakdown($till);
        $this->assertSame(300, $breakdown['bar_cash']);            // lands under bar
        $this->assertSame(0, $breakdown['cash_contributions']);    // never inside contributions
    }

    public function test_a_double_submit_with_the_same_key_commits_exactly_once(): void
    {
        $a = $this->article(500, 10);

        $first = $this->commit([['article_id' => $a->id, 'qty' => 1]], ['idempotency_key' => 'ord-abc']);
        $second = $this->commit([['article_id' => $a->id, 'qty' => 1]], ['idempotency_key' => 'ord-abc']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Order::query()->withoutGlobalScopes()->count());
        $this->assertSame(9, $a->fresh()->stock); // depleted once only
    }

    public function test_voiding_an_order_requires_the_permission(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value); // pos.bar, but not order.void
        $a = $this->article(500, 5);
        $order = $this->commit([['article_id' => $a->id, 'qty' => 1]]);

        $this->expectException(AuthorizationException::class);
        (new VoidOrder)->handle($order, $staff, 'nope');
    }
}
