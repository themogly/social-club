<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\CheckInScreen;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 89 — the counter shows which sede it is on, resolves that from the operator's OWN assignments
 * (never a silent guess), and NEVER writes the admin panel's scope as a side effect of being visited.
 */
class CounterLocationTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $a;

    private Location $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->a = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        $this->b = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte']);
    }

    /** @param list<Location> $sedes */
    private function operator(array $sedes): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::MANAGER->value); // holds pos.use / checkin.manage / pos.bar / till.open
        $user->locations()->sync(array_map(fn (Location $l): string => $l->id, $sedes));

        return $user;
    }

    public function test_a_single_sede_operator_is_adopted_cleanly_and_the_sede_is_displayed(): void
    {
        $this->actingAs($this->operator([$this->a]));

        Livewire::test(DispensaryPos::class)
            ->assertSet('locationId', $this->a->id)
            ->assertSet('noLocation', false)
            ->assertSet('mustChooseLocation', false);

        // Adopted into the counter's OWN state, not the panel scope.
        $this->assertSame($this->a->id, session('counter.location_id'));

        // And the sede name shows on the screen (the shared header renders it for every counter screen).
        $this->actingAs($this->operator([$this->a]))
            ->get(route('counter.pos'))
            ->assertOk()
            ->assertSee('Sede Centro');
    }

    public function test_every_counter_screen_displays_the_active_sede(): void
    {
        $user = $this->operator([$this->a]);

        foreach (['counter.pos', 'counter.checkin', 'counter.bar', 'counter.till'] as $route) {
            $this->actingAs($user)->get(route($route))->assertOk()->assertSee('Sede Centro');
        }
    }

    public function test_a_multi_sede_operator_is_asked_not_silently_adopted(): void
    {
        $this->actingAs($this->operator([$this->a, $this->b]));

        // Panel on "All locations" (scope null): the screen must ASK, never pick one.
        Livewire::test(DispensaryPos::class)
            ->assertSet('locationId', null)
            ->assertSet('noLocation', true)
            ->assertSet('mustChooseLocation', true);

        // Nothing was silently chosen.
        $this->assertNull(session('counter.location_id'));
    }

    public function test_the_multi_sede_screen_prompts_the_operator_to_choose(): void
    {
        $this->actingAs($this->operator([$this->a, $this->b]));

        $this->get(route('counter.pos'))
            ->assertOk()
            ->assertSee(__('Elige tu sede'));
    }

    public function test_visiting_a_counter_screen_does_not_change_the_admin_panel_active_location(): void
    {
        // Panel deliberately on "All locations".
        app(ActiveScope::class)->setLocation(null);
        $this->assertNull(session('scope.location_id'));

        // A single-sede operator (adopts) — the strongest case, since adoption is where the old code leaked.
        $this->actingAs($this->operator([$this->a]));
        Livewire::test(DispensaryPos::class)->assertSet('locationId', $this->a->id);

        // The panel scope is untouched: still "All locations".
        $this->assertNull(session('scope.location_id'));

        // A multi-sede operator with nothing chosen yet likewise never writes the panel scope (start clean —
        // the previous operator's sticky counter choice is theirs, not this one's).
        session()->forget('counter.location_id');
        $this->actingAs($this->operator([$this->a, $this->b]));
        Livewire::test(CheckInScreen::class)->assertSet('locationId', null);
        $this->assertNull(session('scope.location_id'));
    }

    public function test_switching_is_validated_server_side_and_refuses_an_unassigned_sede(): void
    {
        // Operator assigned ONLY to A tries to switch to B.
        $this->actingAs($this->operator([$this->a]));
        session(['counter.location_id' => $this->a->id]);

        $this->from(route('counter.pos'))
            ->post(route('counter.location'), ['location_id' => $this->b->id])
            ->assertRedirect(route('counter.pos'))
            ->assertSessionHas('counterLocationError');

        // The working sede is unchanged — the unassigned sede was refused.
        $this->assertSame($this->a->id, session('counter.location_id'));
    }

    public function test_switching_to_an_assigned_sede_is_applied(): void
    {
        $this->actingAs($this->operator([$this->a, $this->b]));
        session(['counter.location_id' => $this->a->id]);

        $this->from(route('counter.pos'))
            ->post(route('counter.location'), ['location_id' => $this->b->id])
            ->assertRedirect(route('counter.pos'));

        $this->assertSame($this->b->id, session('counter.location_id'));
    }

    public function test_switching_is_refused_while_a_till_is_open_at_the_current_sede(): void
    {
        $user = $this->operator([$this->a, $this->b]);
        $this->actingAs($user);
        session(['counter.location_id' => $this->a->id]);
        (new OpenTill)->handle($this->a, 'POS-1', 10000); // an open till at the current sede

        $this->from(route('counter.till'))
            ->post(route('counter.location'), ['location_id' => $this->b->id])
            ->assertSessionHas('counterLocationError');

        $this->assertSame($this->a->id, session('counter.location_id')); // still A — must close the till first
    }

    public function test_the_switch_control_carries_the_unsaved_work_confirmation(): void
    {
        $this->actingAs($this->operator([$this->a, $this->b]));

        // The switch forms fire the shared unsaved-work confirm before navigating away.
        $this->get(route('counter.pos'))
            ->assertOk()
            ->assertSee('data-counter-sede', false)
            ->assertSee('$store.counter?.dirty', false);
    }

    public function test_an_operator_with_no_sede_sees_the_no_location_state(): void
    {
        $this->actingAs($this->operator([])); // assigned to nothing

        Livewire::test(BarPos::class)
            ->assertSet('locationId', null)
            ->assertSet('noLocation', true)
            ->assertSet('mustChooseLocation', false); // nothing to choose — genuinely unassigned

        $this->get(route('counter.bar'))->assertOk()->assertSee(__('Sin sede asignada'));
    }

    public function test_visiting_the_counter_does_not_write_the_panel_scope_even_via_for_location(): void
    {
        // ResolveMemberEligibility / limits run inside ActiveScope::forLocation during a counter request;
        // that temporary switch must not persist the counter's sede into the panel scope.
        app(ActiveScope::class)->setLocation(null);
        $this->actingAs($this->operator([$this->a]));

        app(ActiveScope::class)->forLocation($this->a->id, fn () => true);

        $this->assertNull(session('scope.location_id'));
    }
}
