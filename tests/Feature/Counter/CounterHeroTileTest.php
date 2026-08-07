<?php

namespace Tests\Feature\Counter;

use App\Enums\Role;
use App\Livewire\Counter\CounterHome;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\CounterScreens;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 208 — the hero tile is a decision now, not an accident of list order.
 *
 * The owner, on the hub: *"I think dispensary should be the main button."* He is right, and the reason is
 * sharper than the preference: `heroTile()` returned `tiles()[0]`, and `CounterScreens` said so in its own
 * comment — *"Recepción is first, and that is now load-bearing."* **Nobody had chosen Recepción.** It is
 * first in an array ordered for the tile grid's reading order and for `landingRouteFor()`'s fallback, and 205
 * quietly made that ordering carry a third job it was never written to hold.
 *
 * So the coupling is what this branch fixes. Reordering the array to promote the dispensary would have moved
 * the grid's reading order and touched the landing fallback — three things changed to change one.
 */
class CounterHeroTileTest extends TestCase
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
        app(ActiveScope::class)->setLocation($this->location->id);
    }

    /** @param  list<string>  $permissions */
    private function operatorWith(array $permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($user);

        return $user;
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::OWNER->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($user);

        return $user;
    }

    // --- The owner's call ---------------------------------------------------------------

    /** The dispensary is the hero out of the box. Fails against `main`, where it was Recepción. */
    public function test_the_hero_is_the_dispensary_by_default(): void
    {
        $this->owner();

        $this->assertSame('counter.pos', Livewire::test(CounterHome::class)->instance()->heroTile()['route']);
    }

    /**
     * The setting is honoured — asserted against the SETTING rather than a literal route, so this test still
     * means something if the default ever moves.
     */
    public function test_the_setting_decides_which_destination_is_the_hero(): void
    {
        $this->owner();

        foreach (['counter.till', 'counter.checkin', 'counter.bar'] as $route) {
            Settings::set('counter_hero', $route);

            $this->assertSame(
                Settings::get('counter_hero', 'counter.pos'),
                Livewire::test(CounterHome::class)->instance()->heroTile()['route'],
                'the hero did not follow the setting',
            );
            $this->assertSame($route, Livewire::test(CounterHome::class)->instance()->heroTile()['route']);
        }
    }

    // --- The property that made deriving it attractive, and would break here -------------

    /**
     * An operator who cannot open the configured hero still gets one they CAN open.
     *
     * This is the case `heroTile()` handled for free when it was `tiles()[0]`, and the one this branch would
     * break if the setting were read without a fallback: a hero the operator cannot open is a tile to a 403.
     */
    public function test_an_operator_who_cannot_open_the_configured_hero_gets_one_they_can(): void
    {
        $this->operatorWith(['till.open']);   // no pos.use — the configured hero is unreachable

        $hero = Livewire::test(CounterHome::class)->instance()->heroTile();

        $this->assertNotNull($hero, 'a till-only operator got no hero at all');
        $this->assertSame('counter.till', $hero['route']);
    }

    /** One reachable destination: it IS the hero, and the grid is not left empty of it. */
    public function test_an_operator_with_one_destination_sees_it_as_the_hero(): void
    {
        $this->operatorWith(['till.open']);

        $component = Livewire::test(CounterHome::class);
        $instance = $component->instance();

        $this->assertSame('counter.till', $instance->heroTile()['route']);
        $this->assertSame([], $instance->secondaryTiles());
        $this->assertStringContainsString('data-counter-home-tile="counter.till"', $component->html());
    }

    /**
     * Every reachable destination renders EXACTLY once — the hero is one of them promoted, never a sixth
     * tile, and never one dropped.
     *
     * This is the assertion that fails on the naive implementation: `secondaryTiles()` used to be
     * `array_slice($tiles, 1)`, so promoting the dispensary would have rendered it twice and left Recepción
     * out of the grid entirely.
     */
    public function test_the_grid_still_renders_every_reachable_destination_exactly_once(): void
    {
        $user = $this->owner();
        $html = Livewire::test(CounterHome::class)->html();

        foreach (CounterScreens::reachableFor($user) as $screen) {
            $this->assertSame(
                1,
                substr_count($html, 'data-counter-home-tile="'.$screen['route'].'"'),
                $screen['route'].' does not render exactly once',
            );
        }

        $this->assertSame(1, substr_count($html, 'data-counter-home-hero'));
        $this->assertStringContainsString('data-counter-home-hero', $html);
    }

    /** The hero is substantially larger than the rest — the whole point of promoting one. */
    public function test_the_hero_is_still_substantially_larger_than_the_others(): void
    {
        $this->owner();
        $html = Livewire::test(CounterHome::class)->html();

        $this->assertStringContainsString('min-h-[11rem]', $html);
        $this->assertStringContainsString('min-h-[8rem]', $html);
    }

    // --- What must NOT have moved --------------------------------------------------------

    /**
     * `CounterScreens` order is unchanged, because it means two other things: the grid's reading order and
     * `landingRouteFor()`'s fallback, which still looks for `counter.checkin` by name.
     */
    public function test_the_counter_screens_order_is_unchanged(): void
    {
        $this->assertSame(
            ['counter.checkin', 'counter.members', 'counter.pos', 'counter.bar', 'counter.till'],
            array_column(CounterScreens::forUser(null), 'route'),
        );
    }

    /** And the landing fallback is untouched by the hero moving. */
    public function test_the_landing_route_is_unaffected_by_the_hero_setting(): void
    {
        $user = $this->owner();
        Settings::set('counter_landing', 'screen');
        Settings::set('counter_hero', 'counter.till');

        $this->assertSame('counter.checkin', CounterScreens::landingRouteFor($user));

        // A till-only operator still lands on the first screen they may open, as before.
        $tillOnly = $this->operatorWith(['till.open']);
        $this->assertSame('counter.till', CounterScreens::landingRouteFor($tillOnly));
    }

    /** A stale or nonsense setting degrades to the old behaviour rather than throwing or blanking the hub. */
    public function test_an_unknown_hero_setting_degrades_to_the_first_reachable_destination(): void
    {
        $this->owner();
        Settings::set('counter_hero', 'counter.does-not-exist');

        $this->assertSame('counter.checkin', Livewire::test(CounterHome::class)->instance()->heroTile()['route']);
    }
}
