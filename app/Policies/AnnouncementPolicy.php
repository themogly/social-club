<?php

namespace App\Policies;

use App\Models\User;

/** Club announcements — owner/manager comms. Gated on `comms.manage`. */
class AnnouncementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('comms.manage');
    }

    public function view(User $user): bool
    {
        return $user->can('comms.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('comms.manage');
    }

    public function update(User $user): bool
    {
        return $user->can('comms.manage');
    }

    public function delete(User $user): bool
    {
        return $user->can('comms.manage');
    }

    public function restore(User $user): bool
    {
        return $user->can('comms.manage');
    }
}
