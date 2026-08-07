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
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 189 — the counter's front door.
 *
 * The owner asked twice for "a page with big grid icons for all the sections", and separately that "the menu
 * at the top is too cramped". One problem: the bar was doing a home screen's job. It filled up honestly —
 * 132 folded the secondary actions into an overflow so five destinations would fit, then 173 moved
 * "Trabajando: …" into the same row — and the row was full before the last one arrived.
 *
 * The load-bearing rule here is ONE source for the destinations and their gates. A tile to a screen the
 * operator cannot open is the same defect as a link to a 403, so these assert against
 * {@see CounterScreens}, never a hard-coded list.
 */
class CounterHomeTest extends TestCase
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

    private function actor(Role $role, bool $withSede = true): User
    {
        $user = User::factory()->create(['pin' => Hash::make('4321')]);
        $user->assignRole($role->value);
        if ($withSede) {
            $user->locations()->attach($this->location->id);
        }

        return $user;
    }

    // --- The tiles are the shared list, and nothing else -----------------------------------------------

    public function test_it_renders_a_tile_for_every_screen_the_operator_may_open_and_none_they_may_not(): void
    {
        foreach ([Role::OWNER, Role::MANAGER, Role::STAFF] as $role) {
            $user = $this->actor($role);
            CounterOperator::set($user);

            $html = Livewire::actingAs($user)->test(CounterHome::class)->html();

            $all = CounterScreens::forUser($user);
            $this->assertNotEmpty($all);

            foreach ($all as $screen) {
                $tile = 'data-counter-home-tile="'.$screen['route'].'"';
                if ($screen['granted']) {
                    $this->assertStringContainsString($tile, $html,
                        "{$role->value} may open {$screen['route']} but has no tile for it.");
                } else {
                    $this->assertStringNotContainsString($tile, $html,
                        "{$role->value} was offered a tile to {$screen['route']}, which they cannot open.");
                }
            }
        }
    }

    public function test_a_user_with_one_counter_permission_sees_one_tile_and_can_use_it(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('till.open');
        $user->locations()->attach($this->location->id);
        CounterOperator::set($user);

        $html = Livewire::actingAs($user)->test(CounterHome::class)->html();

        $this->assertSame(1, substr_count($html, 'data-counter-home-tile='));
        $this->assertStringContainsString('data-counter-home-tile="counter.till"', $html);

        // And the tile is not a link to a 403.
        $this->actingAs($user)->get(route('counter.till'))->assertSuccessful();
    }

    public function test_a_user_with_no_counter_permission_at_all_is_refused(): void
    {
        $user = User::factory()->create();   // no counter permissions, nothing to choose from

        $this->actingAs($user)->get(route('counter.home'))->assertForbidden();
    }

    // --- It is a front door, not a way around a precondition ------------------------------------------

    public function test_the_blocking_chain_still_applies_to_the_home_screen(): void
    {
        // Two assigned sedes with none chosen: the chain is on the SEDE step, exactly as on every other
        // counter screen. The home screen is not an exemption from prompt 175.
        $user = $this->actor(Role::MANAGER, withSede: false);
        $user->locations()->attach(Location::factory()->create(['organisation_id' => $this->org->id])->id);
        $user->locations()->attach(Location::factory()->create(['organisation_id' => $this->org->id])->id);

        $html = Livewire::actingAs($user)->test(CounterHome::class)->html();

        $this->assertStringContainsString('data-blocker="sede"', $html);
        $this->assertStringNotContainsString('data-counter-home-tile=', $html);
    }

    public function test_the_surface_still_owns_identifying_on_the_home_screen(): void
    {
        $user = $this->actor(Role::MANAGER);   // sede resolved, but nobody has identified

        Livewire::actingAs($user)->test(CounterHome::class)
            ->assertSet('surfaceModeState', 'unidentified');
    }

    // --- The terminal operations that came off the bar ------------------------------------------------

    /**
     * **Reversed by prompt 205, deliberately.** 189 moved the terminal operations here and left them in the
     * bar as well; the owner reported the result as "just duplicate data". They are facts about the TERMINAL,
     * not about whichever screen is open, so they live in the bar — and this asserts the hub no longer draws
     * a second copy of any of them. The bar's own copies are asserted in `CounterOneControlPerThingTest`.
     */
    public function test_the_home_screen_no_longer_duplicates_the_terminal_operations(): void
    {
        $user = $this->actor(Role::OWNER);
        CounterOperator::set($user);

        $html = Livewire::actingAs($user)->test(CounterHome::class)->html();

        foreach ([
            'data-counter-home-switch-operator',
            'data-counter-home-lock',
            'data-counter-home-panel',
            'data-counter-home-logout',
            'data-counter-home-sede',
        ] as $hook) {
            $this->assertStringNotContainsString($hook, $html, "The hub still draws {$hook} — the bar owns it.");
        }
    }

    /**
     * The sede switcher offers only sedes the operator may work at — asserted on the BAR, which now owns it.
     *
     * The rule this guards has not changed and is the one that matters: the switcher can never offer a sede
     * the validated POST would refuse. Only its address moved (prompt 205).
     */
    public function test_the_sede_switcher_offers_only_sedes_the_operator_may_work_at(): void
    {
        $user = $this->actor(Role::MANAGER);
        $second = Location::factory()->create(['organisation_id' => $this->org->id]);
        $user->locations()->attach($second->id);
        $forbidden = Location::factory()->create(['organisation_id' => $this->org->id]);
        CounterOperator::set($user);
        session(['counter.location_id' => $this->location->id]);

        $html = (string) $this->actingAs($user)->get(route('counter.home'))->assertOk()->getContent();

        $this->assertStringContainsString('data-counter-sede="'.$this->location->id.'"', $html);
        $this->assertStringContainsString('data-counter-sede="'.$second->id.'"', $html);
        $this->assertStringNotContainsString('data-counter-sede="'.$forbidden->id.'"', $html);
    }

    /**
     * **The lock came back to the bar, and that closes prompt 198.**
     *
     * 189 moved it off the row on the grounds that "locking is not something you do mid-basket". That premise
     * was wrong — locking is exactly what you do when you step away from a counter with a member in front of
     * you — and 198 measured the cost: with the only control on `/counter`, reaching it crossed 196's
     * unsaved-work confirm, so the operator's real choice mid-order was to leave the terminal unlocked or
     * abandon the sale. 198 patched that by folding the lock into the bar's overflow. 205 removes the
     * overflow and makes it a first-class control: one tap, on every screen, no navigation, no confirm.
     */
    public function test_the_lock_is_a_first_class_control_in_the_bar_on_every_screen(): void
    {
        $user = $this->actor(Role::OWNER);
        CounterOperator::set($user);

        foreach (['counter.checkin', 'counter.home'] as $route) {
            $html = (string) $this->actingAs($user)->get(route($route))->assertOk()->getContent();

            $this->assertStringContainsString('data-counter-lock', $html, "{$route} has no lock control");
            $this->assertStringContainsString('data-counter-home-link', $html, "{$route} has no way home");
            // No confirm on the lock: 198's guarantee is that work SURVIVES it, so asking would be the bug.
            $this->assertStringNotContainsString('data-counter-overflow', $html, 'the overflow is gone (prompt 205)');
        }
    }
}
