<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\LocationSwitcher;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationSwitcherTest extends TestCase
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
        $this->a = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->b = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    public function test_only_the_owner_can_switch_to_all_locations(): void
    {
        $switcher = app(LocationSwitcher::class);

        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);

        $this->assertTrue($switcher->canSwitchToAll($owner));
        $this->assertFalse($switcher->canSwitchToAll($manager));
        $this->assertFalse($switcher->canSwitchToAll($staff));
    }

    public function test_a_user_can_only_activate_an_assigned_location(): void
    {
        $switcher = app(LocationSwitcher::class);

        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $manager->locations()->sync([$this->a->id]); // assigned to A only

        $this->assertTrue($switcher->canAccess($manager, $this->a->id));
        $this->assertFalse($switcher->canAccess($manager, $this->b->id));
        $this->assertFalse($switcher->canAccess($manager, null)); // no "All locations"

        // Attempting to switch to B is rejected and does not change the active scope.
        $switcher->switch($manager, $this->a->id);
        $this->assertFalse($switcher->switch($manager, $this->b->id));
        $this->assertSame($this->a->id, app(ActiveScope::class)->locationId());
    }

    public function test_active_location_hides_another_locations_records(): void
    {
        $tillA = TillSession::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->a->id]);
        $tillB = TillSession::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->b->id]);

        app(ActiveScope::class)->setLocation($this->a->id);

        $this->assertNotNull(TillSession::find($tillA->id));
        $this->assertNull(TillSession::find($tillB->id)); // B is out of scope
    }
}
