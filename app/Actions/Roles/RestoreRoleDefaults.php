<?php

namespace App\Actions\Roles;

use App\Actions\RecordAuditLog;
use App\Enums\Role;
use App\Models\RolePermissionOverride;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * "Restaurar valores por defecto" for one role (prompt 262): every override for it is removed, so the role holds the
 * code's defaults again. Owner-role only, like every change on the page; audited with the list that was undone.
 */
class RestoreRoleDefaults
{
    public function handle(Role $role, User $actor): void
    {
        if (! $actor->hasRole(Role::OWNER->value) || $role === Role::OWNER) {
            throw new AuthorizationException('Only the owner restores a role, and the owner role has no overrides.');
        }

        DB::transaction(function () use ($role): void {
            $undone = RolePermissionOverride::query()->where('role', $role->value)->get(['permission', 'granted']);

            if ($undone->isEmpty()) {
                return;
            }

            RolePermissionOverride::query()->where('role', $role->value)->delete();
            SetRolePermission::sync($role);

            (new RecordAuditLog)->handle('role.permissions.restored', null,
                ['overrides' => $undone->mapWithKeys(fn (RolePermissionOverride $o): array => [$o->permission => $o->granted])->all()],
                ['role' => $role->value]);
        });
    }
}
