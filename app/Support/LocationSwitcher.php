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

    /**
     * The "All locations" rollup is offered only to an OWNER who can actually reach MORE THAN ONE location
     * (prompt 148). A rollup of one thing is just the thing: a single-sede club must not be offered a
     * meaningless "All locations", and — because that rollup was the default state — must not have it decide
     * where a batch lands.
     */
    public function canSwitchToAll(User $user): bool
    {
        return $user->hasRole(Role::OWNER->value) && $this->available($user)->count() > 1;
    }

    /**
     * The location that should be active by DEFAULT when the user has made no explicit choice: the single one
     * they can reach — so a one-sede org (owner OR a manager with one assigned sede) names that sede instead of
     * sitting in an ambiguous rollup. Null when they can roll up across several (the owner rollup stays the
     * default there) or when there are none yet (straight after install).
     */
    public function defaultLocationId(User $user): ?string
    {
        if ($this->canSwitchToAll($user)) {
            return null; // a genuine multi-sede owner still defaults to the rollup
        }

        $available = $this->available($user);

        return $available->count() === 1 ? (string) $available->first()?->id : null;
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
