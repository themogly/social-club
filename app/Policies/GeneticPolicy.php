<?php

namespace App\Policies;

use App\Models\Genetic;
use App\Models\User;

/**
 * The genetics catalogue is org-wide. Managing it — viewing, creating, editing and
 * (soft) deleting strains — is gated on the single `genetics.manage` permission.
 * Server-side — the Filament resource authorises through this policy, never by
 * hiding a button.
 */
class GeneticPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('genetics.manage');
    }

    public function view(User $user, Genetic $model): bool
    {
        return $user->can('genetics.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('genetics.manage');
    }

    public function update(User $user, Genetic $model): bool
    {
        return $user->can('genetics.manage');
    }

    public function delete(User $user, Genetic $model): bool
    {
        return $user->can('genetics.manage');
    }

    public function restore(User $user, Genetic $model): bool
    {
        return $user->can('genetics.manage');
    }

    public function forceDelete(User $user, Genetic $model): bool
    {
        return $user->can('genetics.manage');
    }
}
