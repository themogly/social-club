<?php

namespace App\Policies;

use App\Models\Dispensation;
use App\Models\User;
use App\Support\ActiveScope;

/**
 * Dispensations are recorded at the counter (App\Livewire\Counter\DispensaryPos) and
 * are never created or edited through the panel. This policy gates two abilities:
 *
 * - `view` — the printable contribution ticket. The viewer must hold a counter or
 *   reporting permission, be in the SAME organisation, and either be assigned to the
 *   row's own location OR hold org-wide report rights. Object-ownership, not just
 *   authentication (NOTES §B) — the denial tests prove a wrong-location actor is blocked.
 * - `void` — reverse a completed dispensation. Requires `dispensation.void` (manager+)
 *   AND the same visibility check, so a manager cannot void another sede's row.
 */
class DispensationPolicy
{
    /**
     * BROWSE the whole dispensation archive in the panel (DispensationResource, oversight-only, prompt 71).
     * Reporting only — NOT `pos.use` (prompt 122): operating the till serves the member in front of you
     * (CommitDispensation), it does not open every member's Article-9 consumption history. A counter operator's
     * legitimate SINGLE-row reads (receipt reprint, refund lookup) go through `view`, which keeps `pos.use`.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('reports.view');
    }

    public function view(User $user, Dispensation $dispensation): bool
    {
        if (! ($user->can('pos.use') || $user->can('reports.view'))) {
            return false;
        }

        if ($dispensation->organisation_id !== app(ActiveScope::class)->organisationId()) {
            return false;
        }

        return $user->can('reports.view.all')
            || $user->locations()->whereKey($dispensation->location_id)->exists();
    }

    public function void(User $user, Dispensation $dispensation): bool
    {
        return $user->can('dispensation.void') && $this->view($user, $dispensation);
    }

    /**
     * Refund (partial/cash) a completed dispensation (prompt 71). Reuses `dispensation.void` — a deliberate,
     * reversible choice recorded in DECISIONS (a refund is a reversal act in the same risk tier) — AND the
     * same visibility check, so a manager cannot refund another sede's row. RefundDispensation is the writer;
     * this only gates whether the surface is offered.
     */
    public function refund(User $user, Dispensation $dispensation): bool
    {
        return $user->can('dispensation.void') && $this->view($user, $dispensation);
    }
}
