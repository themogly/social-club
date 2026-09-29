<?php

namespace App\Policies;

use App\Models\TillSession;
use App\Models\User;

/**
 * Till sessions are oversight-only in the panel: they are opened and closed at the
 * counter (App\Livewire\Counter\TillSession), never here. Viewing — the index and the
 * Z-report — is gated on holding EITHER till permission (`till.open` OR `till.close`),
 * so both STAFF (who open) and MANAGERs (who close) can review. There is deliberately
 * no create/update/delete ability: a closed session is immutable, and the absence of
 * those methods denies the abilities server-side (Filament authorises through here).
 */
class TillSessionPolicy
{
    // Prompt 309 — the till HISTORY (every session's cash and Z report) is its own switch. Opening and closing the drawer
    // at the counter checks `till.open` / `till.close` there and never comes through this policy.
    public function viewAny(User $user): bool
    {
        return $user->can('panel.tills');
    }

    public function view(User $user, TillSession $model): bool
    {
        return $user->can('panel.tills');
    }
}
