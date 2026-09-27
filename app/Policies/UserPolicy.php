<?php

namespace App\Policies;

use App\Actions\Users\EnsureRoleChangeIsAllowed;
use App\Enums\Role;
use App\Models\User;

/**
 * Staff administration is gated on `staff.manage` (OWNER only). Server-side —
 * the Filament resource authorises through this policy, never by hiding a button.
 *
 * Prompt 270 — `staff.manage` is owner-editable (262), and a manager holding it could edit the OWNER's row (password,
 * PIN, active flag, roles) or promote themselves. An owner's row is now touchable only by an owner; who may assign the
 * owner role is enforced on save ({@see EnsureRoleChangeIsAllowed}).
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('staff.manage');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('staff.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('staff.manage');
    }

    public function update(User $user, User $model): bool
    {
        return $user->can('staff.manage') && $this->mayTouch($user, $model);
    }

    public function delete(User $user, User $model): bool
    {
        // Never delete yourself; deactivate others (soft delete) to preserve attribution.
        return $user->can('staff.manage') && $user->id !== $model->id && $this->mayTouch($user, $model);
    }

    public function restore(User $user, User $model): bool
    {
        return $user->can('staff.manage') && $this->mayTouch($user, $model);
    }

    /** An owner's account is an owner's business. */
    private function mayTouch(User $user, User $model): bool
    {
        return ! $model->hasRole(Role::OWNER->value) || $user->hasRole(Role::OWNER->value);
    }
}
