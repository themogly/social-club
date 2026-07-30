<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\BreachLog;
use App\Models\User;

/**
 * The data-breach incident register (RGPD Art. 33 — the 72-hour AEPD notification). OWNER
 * only: a breach is an organisation-level compliance matter, above any single location's
 * manager. Incidents may be recorded and updated as the response progresses, but never
 * deleted — the register is evidence of what happened and when it was notified.
 */
class BreachLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Role::OWNER->value);
    }

    public function view(User $user, BreachLog $model): bool
    {
        return $user->hasRole(Role::OWNER->value);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::OWNER->value);
    }

    public function update(User $user, BreachLog $model): bool
    {
        return $user->hasRole(Role::OWNER->value);
    }
}
