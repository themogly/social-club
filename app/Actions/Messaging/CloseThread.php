<?php

namespace App\Actions\Messaging;

use App\Enums\MessageThreadStatus;
use App\Models\MessageThread;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/** Close a thread without replying (the club considers it resolved). Gated on comms.manage. A member writing again reopens it. */
class CloseThread
{
    public function handle(MessageThread $thread, User $actor): void
    {
        if (! $actor->can('comms.manage')) {
            throw new AuthorizationException('Closing a thread requires the comms.manage permission.');
        }

        $thread->update([
            'status' => MessageThreadStatus::CLOSED,
            'closed_at' => now(),
            'closed_by' => $actor->id,
        ]);
    }
}
