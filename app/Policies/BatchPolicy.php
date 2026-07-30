<?php

namespace App\Policies;

use App\Models\Batch;
use App\Models\User;

/**
 * Batches are per-location stock. Every action is gated on `stock.manage`. The
 * global LocationScope on Batch already prevents cross-location access, so no extra
 * location check belongs here. Server-side — the Filament resource authorises
 * through this policy, never by hiding a button.
 */
class BatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('stock.manage');
    }

    public function view(User $user, Batch $model): bool
    {
        return $user->can('stock.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('stock.manage');
    }

    public function update(User $user, Batch $model): bool
    {
        return $user->can('stock.manage');
    }

    public function delete(User $user, Batch $model): bool
    {
        return $user->can('stock.manage');
    }

    public function restore(User $user, Batch $model): bool
    {
        return $user->can('stock.manage');
    }

    public function forceDelete(User $user, Batch $model): bool
    {
        return $user->can('stock.manage');
    }
}
