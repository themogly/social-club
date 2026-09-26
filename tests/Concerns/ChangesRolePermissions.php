<?php

namespace Tests\Concerns;

use App\Actions\Roles\SetRolePermission;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Since prompt 262 the owner decides what STAFF and MANAGER may do, and STAFF no longer hold `panel.access` (or lack
 * `member.documents.view`) by default. A test that needs a different matrix changes it the real way — through
 * `SetRolePermission`, as the owner — never by editing the Spatie role by hand.
 */
trait ChangesRolePermissions
{
    protected function setRolePermission(Role $role, string $permission, bool $granted): void
    {
        if (! SpatieRole::query()->where('name', Role::OWNER->value)->exists()) {
            $this->seed(RolePermissionSeeder::class);
        }

        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);

        (new SetRolePermission)->handle($role, $permission, $granted, $owner);
    }

    /** A club that lets its staff into the admin panel (the pre-262 default). */
    protected function giveStaffThePanel(): void
    {
        $this->setRolePermission(Role::STAFF, 'panel.access', true);
    }
}
