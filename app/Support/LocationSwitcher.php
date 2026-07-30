<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The panel location switcher's contract. Managers/staff switch only among their
 * assigned locations; the OWNER also gets "All locations" (null = the org rollup).
 * The chosen location drives LocationScope via ActiveScope, and is persisted.
 */
class LocationSwitcher
{
    /**
     * Locations the user may work in. OWNER sees all org locations; others see only
     * their assignments.
     *
     * @return Collection<int, Location>
     */
    public function available(User $user): Collection
    {
        if ($user->hasRole(Role::OWNER->value)) {
            return Location::query()->active()->orderBy('name')->get();
        }

        return $user->locations()->where('active', true)->orderBy('name')->get();
    }

    /** Only the OWNER may view the cross-location "All locations" rollup. */
    public function canSwitchToAll(User $user): bool
    {
        return $user->hasRole(Role::OWNER->value);
    }

    /** May this user make this location (or "All locations" when null) active? */
    public function canAccess(User $user, ?string $locationId): bool
    {
        if ($locationId === null) {
            return $this->canSwitchToAll($user);
        }

        return $this->available($user)->contains(fn (Location $location) => $location->id === $locationId);
    }

    /**
     * Switch the active location if permitted. Returns whether it was applied.
     */
    public function switch(User $user, ?string $locationId): bool
    {
        if (! $this->canAccess($user, $locationId)) {
            return false;
        }

        app(ActiveScope::class)->setLocation($locationId);

        return true;
    }

    public function current(): ?string
    {
        return app(ActiveScope::class)->locationId();
    }
}
