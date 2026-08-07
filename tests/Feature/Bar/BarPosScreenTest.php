<?php

namespace Tests\Feature\Bar;

use App\Actions\Bar\CommitOrder;
use App\Actions\Till\OpenTill;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Livewire\Counter\BarPos;
use App\Models\Article;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The bar / merch POS screen — a THIN Livewire shell over CommitOrder. These pin the
 * shell's guarantees: the pos.bar gate, the happy path (one Order, right total, depleted
 * unit stock), the articles-only rule (genetics are never offered nor addable), the
 * optional-socio behaviour (cash guest OK; wallet needs a socio), the miscellaneous-line
 * reference requirement, the manager-only void, and the authorization-checked SALE ticket
 * (venta / ticket wording, never aportación). The domain arithmetic itself is pinned in
 * BarPosTest.
 */
class BarPosScreenTest extends TestCase
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

    // --- Fixtures --------------------------------------------------------------

    /** A STAFF operator (STAFF holds pos.bar) assigned to the sede, active location set. */
    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::STAFF->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user); // PIN-identified operator (prompt 26 guard)

        return $user;
    }

    private function article(string $name, int $priceCents, int $stock): Article
    {
        return Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'name' => $name, 'price_cents' => $priceCents, 'stock' => $stock, 'active' => true,
        ]);
    }

    private function member(): Member
    {
        return Member::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function openTill(): void
    {
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
    }

    // --- Tests -----------------------------------------------------------------

    public function test_the_screen_renders_for_a_pos_bar_operator(): void
    {
        $this->article('Agua con gas', 150, 20);
        $this->operator();
        $this->openTill(); // prompt 175: without one the screen is the till blocking state

        Livewire::test(BarPos::class)
            ->assertOk()
            ->assertSet('noLocation', false)
            ->assertSee(__('Artículos'))
            ->assertSee('Agua con gas'); // the article grid lists the sede's active article
    }

    public function test_a_user_without_pos_bar_is_forbidden(): void
    {
        $user = User::factory()->create(); // no role → no pos.bar
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);

        $this->get(route('counter.bar'))->assertForbidden();
    }

    public function test_three_article_lines_commit_to_exactly_one_order_with_the_right_total_and_depleted_stock(): void
    {
        $this->openTill();
        $this->operator();
        $a = $this->article('Cerveza', 250, 10);
        $b = $this->article('Refresco', 150, 5);
        $c = $this->article('Camiseta', 400, 3);

        Livewire::test(BarPos::class)
            ->call('addArticle', $a->id)
            ->call('addArticle', $b->id)
            ->call('addArticle', $c->id)
            ->assertCount('basket', 3)
            ->call('commitOrder')
            ->assertSet('flashType', 'success');

        $this->assertSame(1, Order::query()->withoutGlobalScopes()->count());

        $order = Order::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame(800, $order->total_cents->cents);   // 250 + 150 + 400
        $this->assertSame(800, $order->cash_cents->cents);    // full cash by default (no socio)
        $this->assertSame(0, $order->wallet_cents->cents);
        $this->assertNull($order->member_id);
        $this->assertSame(OrderStatus::COMPLETED, $order->status);
        $this->assertSame(9, $a->fresh()->stock);
        $this->assertSame(4, $b->fresh()->stock);
        $this->assertSame(2, $c->fresh()->stock);
    }

    public function test_only_articles_are_offered_and_a_genetic_can_never_be_added(): void
    {
        $this->operator();
        $this->openTill(); // prompt 175: without one the screen is the till blocking state
        $article = $this->article('Bocadillo', 300, 10);
        // A genetic exists in the org but must NEVER surface on the bar POS.
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia Test']);

        $component = Livewire::test(BarPos::class)
            ->assertSee('Bocadillo')          // the grid shows articles…
            ->assertDontSee('Amnesia Test');  // …and never a genetic

        // The product grid is built ONLY from Article records — no genetic id is present.
        $ids = array_map(fn (array $row): string => (string) $row['id'], $component->viewData('articles'));
        $this->assertContains($article->id, $ids);
        $this->assertNotContains($genetic->id, $ids);

        // There is no path to add a genetic: the id does not resolve as an Article → refused.
        $component
            ->call('addArticle', $genetic->id)
            ->assertCount('basket', 0)
            ->assertSet('flashType', 'error');
    }

    public function test_a_member_less_cash_order_with_a_reference_succeeds(): void
    {
        $this->openTill();
        $this->operator();
        $a = $this->article('Cafe', 120, 10);

        Livewire::test(BarPos::class)
            ->assertSet('memberId', null)
            ->call('addArticle', $a->id)
            ->set('reference', 'Invitado')
            ->call('commitOrder')
            ->assertSet('flashType', 'success');

        $order = Order::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertNull($order->member_id);
        $this->assertSame('Invitado', $order->reference);
        $this->assertSame(120, $order->total_cents->cents);
        $this->assertSame(120, $order->cash_cents->cents);
    }

    public function test_wallet_payment_without_a_socio_is_refused(): void
    {
        $this->openTill();
        $this->operator();
        $a = $this->article('Zumo', 500, 10);

        // No socio attached, yet a wallet amount is entered → the commit is refused and
        // nothing is written (the wallet input is disabled in the UI; this guards the server).
        Livewire::test(BarPos::class)
            ->call('addArticle', $a->id)
            ->assertSet('memberId', null)
            ->set('walletInput', '5,00')
            ->call('commitOrder')
            ->assertSet('flashType', 'error');

        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
    }

    public function test_wallet_payment_with_a_socio_splits_and_commits(): void
    {
        $this->openTill();
        $this->operator();
        $a = $this->article('Bebida', 500, 10);
        $member = $this->member();

        Livewire::test(BarPos::class)
            ->call('addArticle', $a->id)
            ->call('selectMember', $member->id)
            ->assertSet('memberId', $member->id)
            ->set('walletInput', '2,00') // €2 wallet, €3 cash
            ->call('commitOrder')
            ->assertSet('flashType', 'success');

        $order = Order::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame($member->id, $order->member_id);
        $this->assertSame(500, $order->total_cents->cents);
        $this->assertSame(300, $order->cash_cents->cents);
        $this->assertSame(200, $order->wallet_cents->cents);
    }

    public function test_a_miscellaneous_line_without_a_reference_is_rejected(): void
    {
        $this->operator();

        Livewire::test(BarPos::class)
            ->set('miscDescription', 'Propina')
            ->set('miscAmount', '2,00')
            ->set('miscReference', '') // required — omitted
            ->call('addMiscLine')
            ->assertSet('flashType', 'error')
            ->assertCount('basket', 0)
            // With a reference it is accepted.
            ->set('miscReference', 'Evento')
            ->call('addMiscLine')
            ->assertCount('basket', 1);
    }

    public function test_staff_cannot_void_but_a_manager_can(): void
    {
        $this->openTill();
        $operator = $this->operator(); // STAFF → pos.bar but NOT order.void
        $a = $this->article('Snack', 500, 5);

        $order = (new CommitOrder)->handle($this->location, [
            ['article_id' => $a->id, 'qty' => 1],
        ], ['operator_id' => $operator->id]);

        $this->assertSame(4, $a->fresh()->stock);

        // STAFF: the void affordance refuses (permission gate); the order stays COMPLETED.
        Livewire::test(BarPos::class)
            ->set('lastOrderId', $order->id)
            ->set('voidReason', 'Error')
            ->call('voidLast')
            ->assertSet('flashType', 'error');
        $this->assertSame(OrderStatus::COMPLETED, $order->fresh()->status);
        $this->assertSame(4, $a->fresh()->stock);

        // MANAGER: the same action voids and returns the units.
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $manager->locations()->sync([$this->location->id]);
        $this->actingAs($manager);
        app(ActiveScope::class)->setLocation($this->location->id);

        Livewire::test(BarPos::class)
            ->set('lastOrderId', $order->id)
            ->set('voidReason', 'Corrección autorizada')
            ->call('voidLast')
            ->assertSet('flashType', 'success');

        $this->assertSame(OrderStatus::VOIDED, $order->fresh()->status);
        $this->assertSame(5, $a->fresh()->stock); // units returned
    }

    public function test_a_successful_charge_shows_a_colocated_confirmation_and_receipt_link(): void
    {
        // Prompt 41: the state survives commit (candidate #2 ruled out) AND the success flash renders
        // colocated in the basket column, not only in the page-top banner an operator scrolled past.
        $this->openTill();
        $this->operator();
        $a = $this->article('Cerveza', 250, 10);

        $component = Livewire::test(BarPos::class)
            ->call('addArticle', $a->id)
            ->call('commitOrder')
            ->assertSet('flashType', 'success')
            ->assertSee(__('Última venta registrada')); // colocated "last sale" block under Cobrar

        $order = Order::query()->withoutGlobalScopes()->firstOrFail();
        $component->assertSet('lastOrderId', $order->id); // lastOrderId is retained through the render

        // The success flash appears BOTH at the page top and colocated in the basket column.
        $this->assertGreaterThanOrEqual(2, substr_count($component->html(), __('Pedido registrado.')),
            'The success confirmation must render colocated in the basket column, not only page-top.');

        // The receipt link resolves to the correct order via counter.bar.receipt.
        $component->assertSee(route('counter.bar.receipt', $order->id), false);
    }

    public function test_a_failed_charge_shows_the_error_equally_colocated(): void
    {
        // The same discoverability guarantee must cover errors (a silent failure is worse).
        $this->openTill();
        $this->operator();
        $a = $this->article('Zumo', 500, 10);

        $component = Livewire::test(BarPos::class)
            ->call('addArticle', $a->id)
            ->set('walletInput', '5,00') // wallet with no socio → refused
            ->call('commitOrder')
            ->assertSet('flashType', 'error');

        $this->assertGreaterThanOrEqual(2, substr_count($component->html(), __('El pago con monedero requiere un socio.')),
            'The error must be equally visible colocated in the basket column.');
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
    }

    public function test_the_bar_ticket_renders_with_sale_wording_and_never_contribution_wording(): void
    {
        $this->openTill();
        $operator = $this->operator();
        $a = $this->article('Tortilla', 350, 10);
        $member = $this->member();

        $order = (new CommitOrder)->handle($this->location, [
            ['article_id' => $a->id, 'qty' => 1],
        ], ['operator_id' => $operator->id, 'member_id' => $member->id]);

        // Pin es so the sale-vs-contribution vocabulary distinction is asserted in one
        // language (the app default is now en; the request renders es via the session locale).
        app()->setLocale('es');
        $this->withSession(['locale' => 'es'])->get(route('counter.bar.receipt', $order->id))
            ->assertOk()
            ->assertSee(__('Ticket de venta'))     // SALE vocabulary…
            ->assertSee('Tortilla')
            ->assertSee($member->fullName())
            ->assertSee('3,50')                     // the €3,50 total on the ticket
            ->assertDontSee('aportación')           // …deliberately NOT the contribution vocabulary
            ->assertDontSee('Comprobante de aportación')
            ->assertDontSee('dispensación');
    }

    public function test_the_bar_ticket_is_denied_to_a_user_without_permission(): void
    {
        $this->openTill();
        $operator = $this->operator();
        $a = $this->article('Chicle', 100, 10);
        $order = (new CommitOrder)->handle($this->location, [
            ['article_id' => $a->id, 'qty' => 1],
        ], ['operator_id' => $operator->id]);

        $stranger = User::factory()->create(); // no role → no pos.bar / reports.view
        $this->actingAs($stranger);

        $this->get(route('counter.bar.receipt', $order->id))->assertForbidden();
    }

    public function test_the_bar_ticket_is_denied_to_an_operator_at_another_location(): void
    {
        $this->openTill();
        $operator = $this->operator();
        $a = $this->article('Patatas', 200, 10);
        $order = (new CommitOrder)->handle($this->location, [
            ['article_id' => $a->id, 'qty' => 1],
        ], ['operator_id' => $operator->id]);

        // A pos.bar operator, but assigned only to ANOTHER sede → object-ownership denial.
        $otherLocation = Location::factory()->create(['organisation_id' => $this->org->id]);
        $otherOperator = User::factory()->create();
        $otherOperator->assignRole(Role::STAFF->value);
        $otherOperator->locations()->sync([$otherLocation->id]);
        $this->actingAs($otherOperator);

        $this->get(route('counter.bar.receipt', $order->id))->assertForbidden();
    }
}
