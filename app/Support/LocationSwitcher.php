<?php

namespace App\Support;

use App\Enums\LocationKind;
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
    public function available(User $user, bool $includeStores = false): Collection
    {
        // Prompt 277 — the grow / central store has no counter. Every COUNTER caller asks for sedes only (the default),
        // so the store never appears in the counter's sede picker; the PANEL's switcher includes it, so its stock can be
        // looked at and moved.
        $query = $user->hasRole(Role::OWNER->value)
            ? Location::query()->active()
            : $user->locations()->where('active', true);

        return $query->when(! $includeStores, fn ($q) => $q->where('kind', LocationKind::SEDE->value))
            ->orderBy('name')->get();
    }

    /**
     * The "All locations" rollup is offered only to an OWNER who can actually reach MORE THAN ONE location
     * (prompt 148). A rollup of one thing is just the thing: a single-sede club must not be offered a
     * meaningless "All locations", and — because that rollup was the default state — must not have it decide
     * where a batch lands.
     */
    public function canSwitchToAll(User $user): bool
    {
        return $user->hasRole(Role::OWNER->value) && $this->available($user, includeStores: true)->count() > 1;
    }

    /**
     * The location that should be active by DEFAULT when the user has made no explicit choice. Null ONLY for an owner
     * who can roll up across several (the rollup is theirs alone, `canAccess(null)`) or when there is nowhere yet
     * (straight after install). Anyone else gets one of their own: the single one they reach, or — the post-296 audit
     * fix — the first of their SEDES when they reach several (a manager with their sede and the Almacén, since 277). A
     * null here used to mean "no location filter at all", i.e. every sede.
     */
    public function defaultLocationId(User $user): ?string
    {
        if ($this->canSwitchToAll($user)) {
            return null; // a genuine multi-sede owner still defaults to the rollup
        }

        $available = $this->available($user, includeStores: true);
        $first = $available->first(fn (Location $location): bool => $location->kind === LocationKind::SEDE) ?? $available->first();

        return $first !== null ? (string) $first->id : null;
    }

    /**
     * Post-296 audit — put the user on a location they may use, whatever the session says: the rollup kept by a
     * non-owner, or a sede they have since been taken off. Returns whether the active location changed.
     */
    public function enforce(User $user): bool
    {
        if ($this->canAccess($user, $this->current())) {
            return false;
        }

        app(ActiveScope::class)->setLocation($this->defaultLocationId($user));

        return true;
    }

    /** May this user make this location (or "All locations" when null) active? */
    public function canAccess(User $user, ?string $locationId): bool
    {
        if ($locationId === null) {
            return $this->canSwitchToAll($user);
        }

        return $this->available($user, includeStores: true)->contains(fn (Location $location) => $location->id === $locationId);
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
