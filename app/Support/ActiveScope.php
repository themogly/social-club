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
        return session('scope.location_id');
    }

    public function setLocation(?string $locationId): void
    {
        session(['scope.location_id' => $locationId]);
    }

    public function allLocations(): bool
    {
        return $this->locationId() === null;
    }

    /** Run a callback as if a specific location were active, then restore. */
    public function forLocation(?string $locationId, callable $callback): mixed
    {
        $previous = $this->locationId();
        $this->setLocation($locationId);

        try {
            return $callback();
        } finally {
            $this->setLocation($previous);
        }
    }
}
