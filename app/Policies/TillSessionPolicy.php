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
    public function viewAny(User $user): bool
    {
        return $user->can('till.open') || $user->can('till.close');
    }

    public function view(User $user, TillSession $model): bool
    {
        return $user->can('till.open') || $user->can('till.close');
    }
}
