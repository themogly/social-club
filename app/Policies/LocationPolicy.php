<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Location;
use App\Models\User;

/**
 * Per-premises settings are managed under `settings.manage.location`. Removing a
 * premises entirely is a heavier action gated on `locations.manage`. Server-side —
 * the Filament resource authorises through this policy, never by hiding a button.
 *
 * Prompt 270 — `settings.manage.location` is "cambiar los ajustes de SU sede", and it checked only the permission: a
 * manager could edit any sede in the organisation and create new ones. Now a sede is managed only by someone who
 * works there (assigned to it; an owner works everywhere, inactive sedes included), and CREATING one is structural, so it
 * takes `locations.manage` like removing one does.
 */
class LocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('settings.manage.location');
    }

    public function view(User $user, Location $model): bool
    {
        return $user->can('settings.manage.location') && $this->worksAt($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->can('locations.manage');
    }

    public function update(User $user, Location $model): bool
    {
        return $user->can('settings.manage.location') && $this->worksAt($user, $model);
    }

    public function delete(User $user, Location $model): bool
    {
        return $user->can('locations.manage') && $this->worksAt($user, $model);
    }

    public function restore(User $user, Location $model): bool
    {
        return $user->can('settings.manage.location') && $this->worksAt($user, $model);
    }

    private function worksAt(User $user, Location $model): bool
    {
        return $user->hasRole(Role::OWNER->value) || $user->locations()->whereKey($model->id)->exists();
    }
}
