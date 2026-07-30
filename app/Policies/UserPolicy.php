<?php

namespace App\Policies;

use App\Models\User;

/**
 * Staff administration is gated on `staff.manage` (OWNER only). Server-side —
 * the Filament resource authorises through this policy, never by hiding a button.
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
        return $user->can('staff.manage');
    }

    public function delete(User $user, User $model): bool
    {
        // Never delete yourself; deactivate others (soft delete) to preserve attribution.
        return $user->can('staff.manage') && $user->id !== $model->id;
    }

    public function restore(User $user, User $model): bool
    {
        return $user->can('staff.manage');
    }
}
