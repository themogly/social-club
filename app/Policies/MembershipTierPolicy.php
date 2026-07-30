<?php

namespace App\Policies;

use App\Models\MembershipTier;
use App\Models\User;

/**
 * Membership tiers are org-wide templates, administered under `settings.manage`
 * (owner-only). Server-side — the Filament resource authorises through this policy,
 * never by hiding a button.
 */
class MembershipTierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('settings.manage');
    }

    public function view(User $user, MembershipTier $model): bool
    {
        return $user->can('settings.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('settings.manage');
    }

    public function update(User $user, MembershipTier $model): bool
    {
        return $user->can('settings.manage');
    }

    public function delete(User $user, MembershipTier $model): bool
    {
        return $user->can('settings.manage');
    }

    public function restore(User $user, MembershipTier $model): bool
    {
        return $user->can('settings.manage');
    }

    public function forceDelete(User $user, MembershipTier $model): bool
    {
        return $user->can('settings.manage');
    }
}
