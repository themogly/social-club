<?php

namespace App\Policies;

use App\Models\Minute;
use App\Models\User;

/**
 * Actas are governed under `minutes.manage`. A SIGNED acta is immutable: the model
 * refuses any update/delete (Minute::booted) and this policy withholds the edit and
 * delete abilities too, so Filament never even offers the buttons. A correction is
 * never an edit — it is a new acta created with supersedes_id (the "Corregir" action).
 * Server-side authorisation only; never a hidden button.
 */
class MinutePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('minutes.manage');
    }

    public function view(User $user, Minute $model): bool
    {
        return $user->can('minutes.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('minutes.manage');
    }

    public function update(User $user, Minute $model): bool
    {
        return $user->can('minutes.manage') && $model->signed_at === null;
    }

    public function delete(User $user, Minute $model): bool
    {
        return $user->can('minutes.manage') && $model->signed_at === null;
    }
}
