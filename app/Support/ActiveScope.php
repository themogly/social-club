<?php

namespace App\Support;

use App\Models\Organisation;

/**
 * The session-backed scope contract shared by the global scopes, the location
 * switcher (prompt 03/14) and the auto-fill traits.
 *
 * - Organisation: single-org today (resolves to the only organisation), but keyed
 *   so multi-org SaaS is additive later. Settable for tests / future auth.
 * - Location: the operational scope. A specific active location constrains
 *   per-location queries; `null` means "All locations" (the owner rollup).
 *
 * Bound as a singleton in AppServiceProvider.
 */
class ActiveScope
{
    private ?string $organisationId = null;

    private ?string $locationId = null;

    private bool $locationResolved = false;

    public function organisationId(): ?string
    {
        if ($this->organisationId === null) {
            $this->organisationId = session('scope.organisation_id')
                ?? Organisation::query()->orderBy('created_at')->value('id');
        }

        return $this->organisationId;
    }

    public function setOrganisation(?string $organisationId): void
    {
        $this->organisationId = $organisationId;
        session(['scope.organisation_id' => $organisationId]);
    }

    /** The active location id, or null for "All locations". */
    public function locationId(): ?string
    {
        if (! $this->locationResolved) {
            $this->locationId = session('scope.location_id');
            $this->locationResolved = true;
        }

        return $this->locationId;
    }

    /** Set AND persist the active location (the admin panel's location switcher). */
    public function setLocation(?string $locationId): void
    {
        $this->locationId = $locationId;
        $this->locationResolved = true;
        session(['scope.location_id' => $locationId]);
    }

    /**
     * Make a location active for THIS REQUEST ONLY, without persisting it to the session. The counter uses
     * this so its working sede scopes per-location Settings and global scopes for the request WITHOUT
     * writing the admin panel's `scope.location_id` (visiting a counter screen must never silently change
     * the panel's active location — prompt 89).
     */
    public function useLocation(?string $locationId): void
    {
        $this->locationId = $locationId;
        $this->locationResolved = true;
    }

    public function allLocations(): bool
    {
        return $this->locationId() === null;
    }

    /**
     * Run a callback as if a specific location were active, then restore. A TEMPORARY, request-scoped
     * switch — it must not persist to the session (it restores anyway), so it goes through useLocation.
     * Otherwise, called inside a counter request, its restore would write the counter's sede back into the
     * panel's scope.location_id (the exact leak prompt 89 removes).
     */
    public function forLocation(?string $locationId, callable $callback): mixed
    {
        $previous = $this->locationId();
        $this->useLocation($locationId);

        try {
            return $callback();
        } finally {
            $this->useLocation($previous);
        }
    }
}
