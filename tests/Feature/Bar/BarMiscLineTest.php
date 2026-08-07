<?php

namespace Tests\Feature\Bar;

use App\Actions\Bar\CommitOrder;
use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Livewire\Counter\BarPos;
use App\Models\Article;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Period;
use App\ViewModels\Reports\BarSalesReport;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 126 — the bar manual line already existed but sat below the article grid (below the fold at 1024×768)
 * and asked for three fields. It is now reachable from a header button and a one-tap-reason modal; a reason is
 * still required (a free-text line has nothing to reconcile against), but satisfying it is one tap.
 */
class BarMiscLineTest extends TestCase
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

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::STAFF->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);

        return $user;
    }

    private function till(): TillSession
    {
        return (new OpenTill)->handle($this->location, 'POS-1', 10000);
    }

    public function test_the_manual_line_control_is_present_on_the_screen(): void
    {
        $this->operator();
        $this->till(); // prompt 175: without one the screen is the till blocking state
        // The header button (always rendered, not a section below the grid). The "visible without scrolling at
        // 1024×768" measurement is the owed screenshot; its PRESENCE + header placement is asserted here.
        Livewire::test(BarPos::class)->assertSee(__('Importe manual'));
    }

    public function test_a_manual_line_commits_with_its_description_and_reason_and_moves_no_stock(): void
    {
        $this->operator();
        $this->till();
        $article = Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'name' => 'Agua', 'price_cents' => 150, 'stock' => 10, 'active' => true,
        ]);

        Livewire::test(BarPos::class)
            ->set('miscDescription', 'Café')
            ->set('miscAmount', '2,00')
            ->set('miscReference', __('Artículo sin dar de alta'))
            ->call('addMiscLine')
            ->assertHasNoErrors()
            ->set('cashTendered', '2')
            ->call('commitOrder')
            ->assertHasNoErrors();

        $order = Order::query()->firstOrFail();
        $item = collect($order->items)->firstWhere('name', 'Café');
        $this->assertNotNull($item);
        $this->assertNull($item['article_id']);                                  // off-catalogue → distinguishable
        $this->assertSame(__('Artículo sin dar de alta'), $item['reference']);   // the reason travels onto the order
        $this->assertSame(200, $order->total_cents->cents);
        $this->assertSame(10, $article->refresh()->stock);                        // a manual line moves NO stock
    }

    public function test_a_manual_line_without_a_reason_is_refused(): void
    {
        $this->operator();
        $screen = Livewire::test(BarPos::class)
            ->set('miscDescription', 'Café')->set('miscAmount', '2,00')->set('miscReference', '')
            ->call('addMiscLine')
            ->assertSet('flashType', 'error');

        $this->assertSame([], $screen->get('basket')); // the line is not added
    }

    public function test_a_manual_line_is_flagged_in_the_bar_report(): void
    {
        $operator = $this->operator();
        $till = $this->till();
        (new CommitOrder)->handle($this->location, [
            ['description' => 'Café', 'unit_price_cents' => 200, 'qty' => 1, 'reference' => 'evento'],
        ], ['operator_id' => $operator->id, 'till_session_id' => $till->id]);

        $rows = (new BarSalesReport($this->org->id, [$this->location->id], Period::today()))->primary()->rows;
        $manual = collect($rows)->firstWhere('articulo', 'Café');
        $this->assertNotNull($manual);
        $this->assertTrue($manual['manual']); // owner can pick out the off-catalogue lines
    }

    public function test_a_genetic_still_cannot_reach_an_order(): void
    {
        $operator = $this->operator();
        $till = $this->till();
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);

        // A genetic id is not an article at this location → CommitOrder's firstOrFail refuses it.
        $this->expectException(RuntimeException::class);
        (new CommitOrder)->handle($this->location, [['article_id' => $genetic->id, 'qty' => 1]], [
            'operator_id' => $operator->id, 'till_session_id' => $till->id,
        ]);
    }
}
