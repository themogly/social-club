<?php

namespace App\Policies;

use App\Models\DataRequest;
use App\Models\User;

/**
 * Subject-rights requests (RGPD Arts. 15–22) are handled under `data.request.handle`
 * (OWNER only). They may be logged and viewed, but never deleted — the register is the
 * club's evidence that it answered in time. Fulfilment (the data pack / the erasure) runs
 * through the resource's own audited actions, not a raw edit, so there is no update/delete
 * ability here; erasure fulfilment additionally requires `data.erase`.
 */
class DataRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('data.request.handle');
    }

    public function view(User $user, DataRequest $model): bool
    {
        return $user->can('data.request.handle');
    }

    public function create(User $user): bool
    {
        return $user->can('data.request.handle');
    }
}
