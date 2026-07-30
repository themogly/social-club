<?php

namespace App\Policies;

use App\Models\Location;
use App\Models\User;

/**
 * Per-premises settings are managed under `settings.manage.location`. Removing a
 * premises entirely is a heavier action gated on `locations.manage`. Server-side —
 * the Filament resource authorises through this policy, never by hiding a button.
 */
class LocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('settings.manage.location');
    }

    public function view(User $user, Location $model): bool
    {
        return $user->can('settings.manage.location');
    }

    public function create(User $user): bool
    {
        return $user->can('settings.manage.location');
    }

    public function update(User $user, Location $model): bool
    {
        return $user->can('settings.manage.location');
    }

    public function delete(User $user, Location $model): bool
    {
        return $user->can('locations.manage');
    }

    public function restore(User $user, Location $model): bool
    {
        return $user->can('settings.manage.location');
    }
}
