<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    public function test_staff_is_denied_elevated_permissions(): void
    {
        $staff = $this->user(Role::STAFF);

        foreach (['limits.override', 'dispensation.void', 'member.documents.view', 'expenses.overheads'] as $permission) {
            $this->assertTrue(Gate::forUser($staff)->denies($permission), "STAFF should be denied {$permission}");
        }

        // ...but retains its own counter powers.
        $this->assertTrue(Gate::forUser($staff)->allows('pos.use'));
    }

    public function test_owner_holds_all_permissions(): void
    {
        $owner = $this->user(Role::OWNER);

        foreach (['limits.override', 'dispensation.void', 'member.documents.view', 'expenses.overheads', 'staff.manage'] as $permission) {
            $this->assertTrue(Gate::forUser($owner)->allows($permission));
        }
    }

    public function test_staff_admin_resource_is_forbidden_to_staff_but_open_to_owner(): void
    {
        $indexUrl = route('filament.admin.resources.users.index');

        $this->actingAs($this->user(Role::STAFF))->get($indexUrl)->assertForbidden();
        $this->actingAs($this->user(Role::OWNER))->get($indexUrl)->assertOk();
    }
}
