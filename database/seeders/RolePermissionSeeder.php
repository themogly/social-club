<?php

namespace Database\Seeders;

use App\Enums\Role as RoleEnum;
use App\Models\RolePermissionOverride;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Structural seeder — safe and required in EVERY environment (not dev-only): it
 * creates the permission catalogue and the OWNER/MANAGER/STAFF roles with their
 * matrices from App\Support\Permissions — the code's defaults with the club's overrides applied (prompt 262).
 * Idempotent (findOrCreate + syncPermissions).
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permissions::ALL as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Bust the cache so syncPermissions() resolves the just-created permissions
        // (otherwise it reads the stale empty cache from the top of this method).
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Prompt 262 — a permission REMOVED from the catalogue leaves with every override that named it (the
        // catalogue is still the code's alone); the club's other overrides are applied by Permissions::for().
        if (Schema::hasTable('role_permission_overrides')) {
            RolePermissionOverride::query()->whereNotIn('permission', Permissions::ALL)->delete();
        }

        foreach (RoleEnum::cases() as $roleEnum) {
            $role = Role::findOrCreate($roleEnum->value, 'web');
            $role->syncPermissions(Permissions::for($roleEnum));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
