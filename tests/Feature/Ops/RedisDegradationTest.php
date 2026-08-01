<?php

namespace Tests\Feature\Ops;

use App\Enums\Role;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\ViewModels\SystemHealth;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\Support\ExplodingStore;
use Tests\TestCase;

/**
 * Prompt 124 — a Redis blip used to 500 every authenticated screen (the counter included) and the health page
 * whose job is to report it. With the permission cache on the `database` store, authorisation survives an
 * unreachable default (Redis) cache, so the club keeps trading; the paths that still touch Redis surface a
 * message rather than a stack-trace 500.
 */
class RedisDegradationTest extends TestCase
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

    private function owner(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::OWNER->value);
        $user->locations()->sync([$this->location->id]);

        return $user;
    }

    /** Point the DEFAULT cache at a store whose every call throws — an unreachable Redis. */
    private function breakDefaultCache(): void
    {
        Cache::extend('exploding', fn (): Repository => new Repository(new ExplodingStore));
        config(['cache.stores.exploding' => ['driver' => 'exploding']]);
        config(['cache.default' => 'exploding']);
    }

    // --- The branch: authenticated screens still render ------------------------------

    public function test_the_counter_and_dashboard_render_when_the_default_cache_is_unreachable(): void
    {
        $owner = $this->owner();
        // Warm the permission cache on the database store, then take the DEFAULT (Redis) cache down.
        $owner->can('pos.use');
        $this->breakDefaultCache();

        $this->actingAs($owner);
        foreach ([route('counter.pos'), route('counter.bar'), route('counter.till'), route('counter.checkin'), '/'] as $url) {
            $this->get($url)->assertOk(); // was 500 before prompt 124
        }
    }

    // --- System Health survives what it reports on -----------------------------------

    public function test_system_health_renders_and_reports_the_cache_as_degraded(): void
    {
        $this->breakDefaultCache();

        // The VM's probe degrades to "unreachable" instead of throwing.
        $snapshot = (new SystemHealth)->cache();
        $this->assertFalse($snapshot['reachable']);

        // And the page renders (200) rather than dying with the thing it monitors.
        $this->actingAs($this->owner());
        $this->get(\App\Filament\Pages\SystemHealth::getUrl())
            ->assertOk()
            ->assertSee(__('No accesible'));
    }

    // --- Login says something true (not a silent bounce) -----------------------------

    public function test_the_login_page_loads_and_a_redis_failure_surfaces_a_message(): void
    {
        // A guest can always load the form.
        $this->get(route('filament.admin.auth.login'))->assertOk();

        // A request whose path touches the unreachable cache degrades to a stated 503, never a blank bounce.
        $this->breakDefaultCache();
        Route::middleware('web')->get('/__redis_probe', function (): void {
            Cache::get('anything'); // hits the exploding default store
        });

        $this->get('/__redis_probe')
            ->assertStatus(503)
            ->assertSee(__('El sistema no está disponible temporalmente (infraestructura degradada). Inténtalo de nuevo en unos momentos.'));
    }

    // --- Permission changes still propagate with the new store -----------------------

    public function test_permission_changes_take_effect_after_cache_reset(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::STAFF->value);
        $this->assertFalse($user->can('reports.view'));  // staff lacks it (prompt 122)

        $user->givePermissionTo('reports.view');
        $this->artisan('permission:cache-reset')->assertSuccessful();

        $this->assertTrue($user->fresh()->can('reports.view')); // the change is visible after a reset
    }

    // --- Recovery is automatic -------------------------------------------------------

    public function test_recovery_the_cache_returning_restores_normal_operation(): void
    {
        $owner = $this->owner();
        $owner->can('pos.use');

        $this->breakDefaultCache();
        $this->actingAs($owner)->get(route('counter.pos'))->assertOk();

        // Cache back to a working store — no restart, everything works.
        config(['cache.default' => 'array']);
        $this->actingAs($owner)->get(route('counter.pos'))->assertOk();
        $this->assertTrue((new SystemHealth)->cache()['reachable']);
    }
}
