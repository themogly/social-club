<?php

namespace App\Policies;

use App\Models\Member;
use App\Models\User;

/**
 * The member directory is org-wide. Viewing is gated on `members.view`, creation
 * on `members.create` and every mutation (edit, soft delete, restore) on
 * `members.edit`. Members soft-delete only — there is deliberately no force-delete
 * ability, so attribution and history are never destroyed. Server-side — the
 * Filament resource authorises through this policy, never by hiding a button.
 */
class MemberPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('members.view');
    }

    public function view(User $user, Member $model): bool
    {
        return $user->can('members.view');
    }

    public function create(User $user): bool
    {
        return $user->can('members.create');
    }

    public function update(User $user, Member $model): bool
    {
        return $user->can('members.edit');
    }

    public function delete(User $user, Member $model): bool
    {
        return $user->can('members.edit');
    }

    public function restore(User $user, Member $model): bool
    {
        return $user->can('members.edit');
    }
}
