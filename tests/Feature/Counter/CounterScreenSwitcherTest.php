<?php

namespace Tests\Feature\Counter;

use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 42's screen switcher — **re-pointed at the hub by prompt 205, not deleted.**
 *
 * 42 put the switcher in the top bar; 205 moved the destinations to the hub's tiles because after 189 they
 * were in BOTH places, which is what the owner reported as "just duplicate data". The surface changed; every
 * rule 42 established is still a rule and is still asserted here:
 *
 *   · permission-filtered by the REAL per-screen gate — a link to a 403 is never rendered
 *   · the current screen is marked, not offered as a link to itself
 *   · 44px targets
 *
 * The one rule that genuinely retired with the strip is the per-link unsaved-work confirm: with the basket
 * surviving navigation (prompt 205), moving between counter screens no longer loses work, and asking would
 * be the bug. The confirm now lives on the links that LEAVE the counter — asserted in `AlpineScopeTest`.
 */
class CounterScreenSwitcherTest extends TestCase
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

    /** @param  list<string>  $permissions */
    private function operator(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        CounterOperator::set($user);
        app(ActiveScope::class)->setLocation($this->location->id);

        return $user;
    }

    public function test_a_full_access_operator_is_offered_every_screen_from_the_hub(): void
    {
        $this->operator(['checkin.manage', 'membership.fee.collect', 'pos.use', 'pos.bar', 'till.open', 'till.close']);

        $response = $this->get(route('counter.home'));

        $response->assertOk()
            ->assertSee(__('Recepción'))
            ->assertSee(__('Socios'))
            ->assertSee(__('Dispensario'))
            ->assertSee(__('Barra'))
            ->assertSee(__('Caja'));

        foreach (['counter.checkin', 'counter.members', 'counter.pos', 'counter.bar', 'counter.till'] as $route) {
            $response->assertSee('data-counter-home-tile="'.$route.'"', false);
        }
    }

    public function test_a_single_permission_operator_only_sees_their_own_screen(): void
    {
        // pos.bar only — no checkin.manage / pos.use / till.*
        $this->operator(['pos.bar']);

        $response = $this->get(route('counter.home'));

        // Never a tile to a screen this user would 403 on — 42's rule, on 205's surface.
        $response->assertOk()
            ->assertSee('data-counter-home-tile="counter.bar"', false)
            ->assertDontSee('data-counter-home-tile="counter.checkin"', false)
            ->assertDontSee('data-counter-home-tile="counter.pos"', false)
            ->assertDontSee('data-counter-home-tile="counter.till"', false);
    }

    /**
     * The confirm fires where work would be lost, and not on a trip that loses nothing (prompt 205).
     *
     * Leaving the counter (Panel, Log out) still destroys a basket, so both keep it. A tile is a move to
     * another counter screen, where the basket is now held server-side — asking there would be a lie.
     */
    public function test_the_confirm_is_on_the_links_that_leave_and_not_on_the_tiles(): void
    {
        $this->operator(['checkin.manage', 'pos.use', 'pos.bar', 'till.open', 'till.close']);

        $html = (string) $this->get(route('counter.home'))->getContent();

        $this->assertStringContainsString('$store.counter?.dirty', $html, 'the confirm is gone entirely');
        $this->assertStringContainsString('data-counter-logout', $html);

        // No tile carries it: read each tile's own markup rather than the page's.
        preg_match_all('/<a[^>]*data-counter-home-tile="[^"]*"[^>]*>/s', $html, $tiles);
        $this->assertNotEmpty($tiles[0], 'no tiles found — nothing was audited');
        foreach ($tiles[0] as $tile) {
            $this->assertStringNotContainsString('$store.counter?.dirty', $tile, 'a tile asks about work it will not lose');
        }
    }

    /** Where you ARE is still marked — it is the Home link now, which is the one nav control left. */
    public function test_the_current_location_in_the_product_is_still_marked(): void
    {
        $this->operator(['checkin.manage', 'pos.use', 'pos.bar', 'till.open', 'till.close']);

        $this->get(route('counter.home'))->assertOk()->assertSee('aria-current="page"', false);

        // On a working screen the Home link is a link, not the current page.
        $onTill = (string) $this->get(route('counter.till'))->assertOk()->getContent();
        preg_match('/data-counter-home-link.*?<\/a>/s', $onTill, $link);
        $this->assertNotEmpty($link);
        $this->assertStringNotContainsString('aria-current', $link[0]);
    }

    /**
     * The tiles are far larger than the 44px floor, and the bar's own controls still meet it.
     *
     * 130's scrollable-strip rule retired with the strip — there is nothing left in the middle of the row to
     * overlap its neighbours, which is a stronger guarantee than the one it replaced. What survives is the
     * floor itself, and the uniform breakpoint-gated labelling (116's `md:inline` must not come back).
     * **The threshold moved `lg` → `xl` in prompt 206**, measured: that branch widened the row and at 1024
     * the labelled version overlapped the sede badge by 68px. All-or-nothing is the rule; where it flips is
     * whatever the measurement says.
     */
    public function test_the_tiles_clear_the_touch_floor_and_the_bar_labels_uniformly(): void
    {
        $this->operator(['checkin.manage', 'membership.fee.collect', 'pos.use', 'pos.bar', 'till.open', 'till.close']);
        $html = (string) $this->get(route('counter.home'))->getContent();

        // Tiles: 8rem secondary, 11rem hero — an order of magnitude over the floor, which is the whole
        // reason a hub beats a menu bar on a tablet.
        $this->assertMatchesRegularExpression('/data-counter-home-tile="counter\.\w+"[^>]*\n?[^>]*min-h-\[8rem\]/s', $html);
        $this->assertStringContainsString('min-h-[11rem]', $html);

        // The bar's controls: uniform labelling gated at xl, 116's md:inline still gone.
        $this->assertStringContainsString('hidden xl:inline', $html);
        $this->assertStringNotContainsString('hidden md:inline', $html);
        $this->assertStringNotContainsString('hidden lg:inline', $html);
        $this->assertStringContainsString('min-h-11', $html);
    }

    public function test_the_dispensary_filter_chips_are_44px_touch_targets(): void
    {
        // Prompt 130 — the dispensary filter chips were 28px (px-3 py-1); on a touch tablet they must be 44px.
        $blade = file_get_contents(resource_path('views/livewire/counter/dispensary-pos.blade.php'));

        $this->assertStringContainsString('min-h-11', $blade);
        $this->assertStringNotContainsString('rounded-full border px-3 py-1', $blade); // the old sub-44px chip is gone
    }
}
