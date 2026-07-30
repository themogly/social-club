<?php

namespace App\Policies;

use App\Models\MemberApplication;
use App\Models\User;

/**
 * The pre-registration review queue. Reviewing an application — listing, opening
 * and deciding it (approve/reject/waiting list) — is gated on `applications.review`.
 * Enrolling a person by hand is a `members.create` operation. Server-side — the
 * Filament resource authorises through this policy, never by hiding a button.
 */
class MemberApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('applications.review');
    }

    public function view(User $user, MemberApplication $model): bool
    {
        return $user->can('applications.review');
    }

    public function create(User $user): bool
    {
        return $user->can('members.create');
    }

    public function update(User $user, MemberApplication $model): bool
    {
        return $user->can('applications.review');
    }
}
