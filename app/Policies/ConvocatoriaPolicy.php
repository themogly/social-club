<?php

namespace App\Policies;

use App\Models\Convocatoria;
use App\Models\User;

/**
 * Convocatorias are governance, gated on `minutes.manage` (the same authority that manages actas). An
 * ISSUED convocatoria is immutable: the model refuses update/delete (Convocatoria::booted) and this policy
 * withholds the edit and delete abilities too, so Filament never even offers the buttons. Server-side
 * authorisation only — never a hidden button.
 */
class ConvocatoriaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('minutes.manage');
    }

    public function view(User $user, Convocatoria $model): bool
    {
        return $user->can('minutes.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('minutes.manage');
    }

    public function update(User $user, Convocatoria $model): bool
    {
        return $user->can('minutes.manage') && ! $model->isIssued();
    }

    public function delete(User $user, Convocatoria $model): bool
    {
        return $user->can('minutes.manage') && ! $model->isIssued();
    }
}
