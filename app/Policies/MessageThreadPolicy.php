<?php

namespace App\Policies;

use App\Models\MessageThread;
use App\Models\User;

/**
 * Messaging is gated on `comms.manage` (OWNER + MANAGER). Staff never ORIGINATE a thread (members do) and a
 * thread is evidence — so no create, no delete; replies/close/convert happen through the domain actions, each
 * re-checking the permission.
 */
class MessageThreadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('comms.manage');
    }

    public function view(User $user, MessageThread $thread): bool
    {
        return $user->can('comms.manage');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, MessageThread $thread): bool
    {
        return $user->can('comms.manage');
    }

    public function delete(User $user, MessageThread $thread): bool
    {
        return false;
    }
}
