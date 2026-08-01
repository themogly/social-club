<?php

namespace App\Livewire\Counter\Concerns;

use App\Models\Location;
use App\Support\ActiveScope;
use App\Support\LocationSwitcher;

/**
 * The counter's working sede, kept SEPARATE from the admin panel's scope (prompt 89).
 *
 * The old behaviour read the panel's `scope.location_id` and, when it was null ("All locations"), silently
 * adopted the operator's alphabetically-first assigned sede AND wrote that guess back into the panel scope —
 * so visiting a counter screen quietly changed the admin panel's active location, and nothing on screen said
 * which sede you were on.
 *
 * Instead, the counter owns its own location state in the session key `counter.location_id` and NEVER writes
 * `scope.location_id`. Resolution when nothing is chosen yet: one assigned sede → adopt it (nothing to
 * choose); several → ASK (mustChooseLocation), never guess; none → no sede. The chosen sede is made active
 * for the request in-memory (useLocation) so per-location Settings and scopes resolve correctly, without
 * touching the panel. Switching goes through the validated LocationSwitcher path (the POST /counter/location
 * route), never a raw setLocation from client input.
 */
trait ResolvesCounterLocation
{
    /** Several assigned sedes and none chosen yet: the operator must pick — we never silently guess one. */
    public bool $mustChooseLocation = false;

    /** Resolve the counter's working sede from its OWN state; never reads or writes the panel scope. */
    protected function resolveCounterLocation(): void
    {
        $user = $this->currentUser();
        $available = $user !== null
            ? app(LocationSwitcher::class)->available($user)
            : collect();

        $chosen = session('counter.location_id');
        $chosen = is_string($chosen) ? $chosen : null;

        if ($chosen !== null && $available->contains(fn (Location $l): bool => $l->id === $chosen)) {
            $this->locationId = $chosen;
        } elseif ($available->count() === 1) {
            // One sede: adopt it without ceremony — there is nothing to choose.
            $only = $available->first();
            $this->locationId = $only?->id;
            session(['counter.location_id' => $this->locationId]);
        } else {
            // Zero (no assignment) or several with none chosen (must choose) — never silently pick one.
            $this->locationId = null;
            $this->mustChooseLocation = $available->count() > 1;
        }

        $this->noLocation = $this->locationId === null;
        $this->applyCounterScope();
    }

    /** Livewire lifecycle: re-apply the in-memory scope on EVERY request (mount + each update). */
    public function bootedResolvesCounterLocation(): void
    {
        $this->applyCounterScope();
    }

    /** Make the counter's sede active for this request only — never persisted to the panel scope. */
    protected function applyCounterScope(): void
    {
        if ($this->locationId !== null) {
            app(ActiveScope::class)->useLocation($this->locationId);
        }
    }
}
