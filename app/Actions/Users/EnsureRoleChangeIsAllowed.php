<?php

namespace App\Actions\Users;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * Who may hand out which role (prompt 270) — the server-side half of the Personal form's filtered role list.
 *
 * `staff.manage` is owner-editable (262). Granted to a manager, it let them set their own roles to OWNER — and with it
 * everything the roles page is gated on the owner ROLE to protect. So, whatever the form was made to submit:
 *   · only an owner may give the owner role, or take it away;
 *   · nobody but an owner changes their OWN roles.
 * An owner is unrestricted (the roles page already keeps at least the OWNER row fixed).
 */
class EnsureRoleChangeIsAllowed
{
    /**
     * @param  array<int, int|string>  $roleIds  the role ids the form is about to save
     *
     * @throws AuthorizationException
     */
    public function handle(User $actor, ?User $target, array $roleIds): void
    {
        if ($actor->hasRole(Role::OWNER->value)) {
            return;
        }

        $requested = collect($roleIds)->map(fn (int|string $id): string => (string) $id)->sort()->values()->all();
        $ownerRoleId = (string) RoleModel::query()->where('name', Role::OWNER->value)->value('id');

        if (in_array($ownerRoleId, $requested, true) || ($target?->hasRole(Role::OWNER->value) ?? false)) {
            throw new AuthorizationException(__('Solo el propietario puede asignar o retirar el rol de propietario.'));
        }

        if ($target !== null && $target->is($actor)) {
            $current = $target->roles()->pluck('id')->map(fn (int|string $id): string => (string) $id)->sort()->values()->all();

            if ($current !== $requested) {
                throw new AuthorizationException(__('Tus propios roles solo los puede cambiar el propietario.'));
            }
        }
    }
}
