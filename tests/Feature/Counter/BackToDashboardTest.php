<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Livewire\Counter\TillSession;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every counter screen (check-in / till / dispensary POS / bar POS) shares ONE header
 * component. It offers a back-to-dashboard link ONLY to a user who can access the panel
 * (the same gate the sidebar uses) — a locked-down counter-only login sees no path into
 * admin — and confirms before leaving with unsaved work.
 */
class BackToDashboardTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $routes = ['counter.checkin', 'counter.till', 'counter.pos', 'counter.bar'];

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

    private function user(Role $role, bool $active = true): User
    {
        $user = User::factory()->create(['active' => $active]);
        $user->assignRole($role->value);
        $user->locations()->attach($this->location);

        return $user;
    }

    public function test_a_panel_user_sees_the_shared_back_to_dashboard_link_on_every_counter_screen(): void
    {
        $owner = $this->user(Role::OWNER);

        foreach ($this->routes as $route) {
            $response = $this->actingAs($owner)->get(route($route));
            $response->assertOk();
            $response->assertSee('data-counter-topbar', false);   // the ONE shared header
            $response->assertSee('data-counter-dashboard', false); // the back-to-dashboard link
            $response->assertSee(url('/'), false);
        }
    }

    public function test_a_counter_only_user_without_panel_access_sees_no_dashboard_link(): void
    {
        // Has the counter permission (via STAFF) but canAccessPanel() is false (inactive) —
        // the deliberate lockdown for a fixed till tablet. The shared header still renders;
        // the dashboard link does not.
        $counterOnly = $this->user(Role::STAFF, active: false);
        $this->assertFalse($counterOnly->canAccessPanel(Filament::getPanel('admin')));

        foreach ($this->routes as $route) {
            $response = $this->actingAs($counterOnly)->get(route($route));
            $response->assertOk();
            $response->assertSee('data-counter-topbar', false); // shared header present
            $response->assertDontSee('data-counter-dashboard', false); // but NO way into the panel
        }
    }

    public function test_leaving_is_confirmed_when_there_is_unsaved_counter_work(): void
    {
        $owner = $this->user(Role::OWNER);

        // The header controls guard navigation on the shared `counter.dirty` store.
        $this->actingAs($owner)->get(route('counter.checkin'))->assertSee('$store.counter', false);

        // The POS/bar screens flag a non-empty basket as unsaved work (the @script is
        // HTML-encoded in wire:effects, so match the method call without the quoted arg).
        $this->actingAs($owner)->get(route('counter.pos'))->assertSee('$wire.$watch(', false);
        $this->actingAs($owner)->get(route('counter.bar'))->assertSee('$wire.$watch(', false);
        // The till flags an in-progress blind count / cash entry.
        $this->actingAs($owner)->get(route('counter.till'))->assertSee('$wire.$watch(', false);
    }

    public function test_the_till_close_flow_has_a_cancel_control_at_the_blind_count_step(): void
    {
        $owner = $this->user(Role::OWNER);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $this->assertTrue(method_exists(TillSession::class, 'cancelClose'));

        Livewire::actingAs($owner)->test(TillSession::class)
            ->call('startClose')
            ->assertSee(__('Cancelar'))
            ->call('cancelClose')
            ->assertOk();
    }
}
