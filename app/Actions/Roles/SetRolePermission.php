<?php

namespace App\Actions\Roles;

use App\Actions\RecordAuditLog;
use App\Enums\Role;
use App\Models\RolePermissionOverride;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Grant or revoke ONE permission for STAFF or MANAGER — the owner's choice on Sistema ▸ Roles y permisos (prompt 262).
 *
 * Stored as an override of the code's default (or the override removed, when the choice matches the default again),
 * then the Spatie role is re-synced to `Permissions::for()` so the change is live now — and `csc:sync-permissions`
 * converges on the same defaults + overrides on every deploy, so it is never reverted. Audited: who, which role, which
 * permission, from → to.
 *
 * Gated on the OWNER ROLE, not a permission: if approving this were a permission, the owner could grant it to a
 * manager and that manager could then grant themselves anything. For the same reason the OWNER role itself is never
 * editable here — the owner always holds everything.
 */
class SetRolePermission
{
    public function handle(Role $role, string $permission, bool $granted, User $actor): void
    {
        if (! $actor->hasRole(Role::OWNER->value)) {
            throw new AuthorizationException('Only the owner changes what a role may do.');
        }

        if ($role === Role::OWNER) {
            throw new AuthorizationException('The owner always holds every permission.');
        }

        if (! in_array($permission, Permissions::ALL, true)) {
            throw new RuntimeException("Unknown permission: {$permission}");
        }

        DB::transaction(function () use ($role, $permission, $granted, $actor): void {
            $from = in_array($permission, Permissions::for($role), true);

            if ($from === $granted) {
                return;
            }

            $default = in_array($permission, Permissions::defaultsFor($role), true);

            if ($granted === $default) {
                RolePermissionOverride::query()->where('role', $role->value)->where('permission', $permission)->delete();
            } else {
                RolePermissionOverride::query()->updateOrCreate(
                    ['role' => $role->value, 'permission' => $permission],
                    ['granted' => $granted, 'set_by' => $actor->id],
                );
            }

            self::sync($role);

            (new RecordAuditLog)->handle('role.permission.changed', null, ['granted' => $from], [
                'role' => $role->value,
                'permission' => $permission,
                'granted' => $granted,
                'default' => $default,
            ]);
        });
    }

    /** Make the live Spatie role match defaults + overrides now, and drop the permission cache. */
    public static function sync(Role $role): void
    {
        SpatieRole::findOrCreate($role->value, 'web')->syncPermissions(Permissions::for($role));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
