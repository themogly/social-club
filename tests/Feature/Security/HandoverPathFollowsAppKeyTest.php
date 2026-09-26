<?php

namespace Tests\Feature\Security;

use App\Enums\Role;
use App\Http\Middleware\EnforceCounterHandover;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterHandover;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Tests\Feature\Security\Concerns\PostsLivewireOverHttp;
use Tests\TestCase;

/**
 * Prompt 254, test 6 — the handover's allowlist follows Livewire's update endpoint wherever APP_KEY puts it.
 *
 * Livewire 4 hashes the endpoint prefix from APP_KEY (`livewire-<hash>/update`), so a hard-coded path — or the
 * dead `livewire/*` pattern this replaced — breaks the day the key rotates. The application is BOOTED with a
 * different key here (set before any provider boots), so the route itself moves, and the PIN
 * must still reach `unlockOperator()` through the real endpoint.
 */
class HandoverPathFollowsAppKeyTest extends TestCase
{
    use PostsLivewireOverHttp, RefreshDatabase;

    private const OTHER_KEY = 'base64:dGhpcy1pcy1hLWRpZmZlcmVudC1rZXktZm9yLTI1NCE=';

    /**
     * Boot the application with a different key. Set in CONFIG, right after the configuration bootstrapper and
     * before any provider boots — so Livewire registers its update route under the new hash. Not via the
     * environment: Laravel's env repository is a process-wide immutable writer that re-applies `.env` on every
     * boot after the first, so an env override only held when this test ran first in the process.
     */
    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $app->afterBootstrapping(LoadConfiguration::class, fn (Application $app) => $app['config']->set('app.key', self::OTHER_KEY));
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_the_pin_reaches_the_endpoint_under_a_different_app_key(): void
    {
        $this->assertSame(self::OTHER_KEY, config('app.key'), 'precondition: the app booted with the other key');
        $this->assertSame(EndpointResolver::updatePath(), app('livewire')->getUpdateUri());
        $underOriginalKey = '/livewire-'.substr(hash('sha256', env('APP_KEY').'livewire-endpoint'), 0, 8).'/update';
        $this->assertNotSame($underOriginalKey, EndpointResolver::updatePath(), 'precondition: the endpoint moved with the key');
        $this->assertTrue(EnforceCounterHandover::allows(EndpointResolver::updatePath()));
        $this->assertFalse(EnforceCounterHandover::allows('/livewire/update'), 'the dead pattern must not come back');

        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $location = Location::factory()->create(['organisation_id' => $org->id]);
        app(ActiveScope::class)->setLocation($location->id);
        $device = User::factory()->create();
        $device->assignRole(Role::OWNER->value);
        $device->locations()->attach($location->id);
        $operator = User::factory()->create(['pin' => Hash::make('1357')]);
        $operator->assignRole(Role::STAFF->value);
        $operator->locations()->attach($location->id);

        $this->actingAs($device);
        $this->post(route('counter.location'), ['location_id' => $location->id]);
        CounterHandover::begin($operator->id, $location->id);

        $this->livewirePost(
            $this->snapshotFrom('/counter/checkin', 'counter.check-in-screen'),
            ['operatorPin' => '1357'],
            [['unlockOperator']],
        )->assertOk();

        $this->assertFalse(CounterHandover::active());
        $this->assertSame($operator->id, CounterOperator::id());
    }
}
