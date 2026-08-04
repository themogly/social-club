<?php

namespace App\Actions\Messaging;

use App\Enums\MessageAuthor;
use App\Enums\MessageThreadStatus;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\User;
use App\Notifications\NewMemberMessageNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The club side: a staff reply to a member's thread. Gated on comms.manage. Marks the member's outstanding
 * messages read (the club has seen them), appends a STAFF message, optionally closes the thread, then pushes
 * the member a notification of the reply (best-effort — gated by their opt-out + VAPID).
 */
class ReplyToThread
{
    public function handle(MessageThread $thread, User $actor, string $body, bool $close = false): Message
    {
        if (! $actor->can('comms.manage')) {
            throw new AuthorizationException('Replying to a member requires the comms.manage permission.');
        }

        $body = trim($body);
        if ($body === '') {
            throw new RuntimeException('A reply needs a body.');
        }

        $message = DB::transaction(function () use ($thread, $actor, $body, $close): Message {
            $thread->messages()
                ->where('author', MessageAuthor::MEMBER->value)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            $message = Message::create([
                'thread_id' => $thread->id,
                'author' => MessageAuthor::STAFF,
                'user_id' => $actor->id,
                'body' => $body,
            ]);

            $thread->update([
                'last_message_at' => now(),
                'status' => $close ? MessageThreadStatus::CLOSED : $thread->status,
                'closed_at' => $close ? now() : $thread->closed_at,
                'closed_by' => $close ? $actor->id : $thread->closed_by,
            ]);

            return $message;
        });

        if ($thread->member !== null) {
            $thread->member->notify(new NewMemberMessageNotification($thread));
        }

        return $message;
    }
}
