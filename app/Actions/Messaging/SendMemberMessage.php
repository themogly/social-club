<?php

namespace App\Actions\Messaging;

use App\Enums\MembershipStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageThreadStatus;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageThread;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The member side of messaging: start a new thread, or append to one they already own. Scoped entirely to the
 * authenticated socio — a member can only ever write to their OWN thread (checked here, belt to the
 * controller's braces). A member writing again REOPENS a closed thread: the club considered it done, the
 * member did not. This is a contact channel — the body is free text, never a product or quantity.
 */
class SendMemberMessage
{
    public function start(Member $member, string $subject, string $body): MessageThread
    {
        $subject = trim($subject);
        $body = trim($body);
        if ($subject === '' || $body === '') {
            throw new RuntimeException('A new message needs a subject and a body.');
        }

        return DB::transaction(function () use ($member, $subject, $body): MessageThread {
            $thread = MessageThread::create([
                'organisation_id' => $member->organisation_id,
                'member_id' => $member->id,
                'location_id' => $this->primaryLocationId($member),
                'subject' => $subject,
                'status' => MessageThreadStatus::OPEN,
                'last_message_at' => now(),
            ]);

            Message::create([
                'thread_id' => $thread->id,
                'author' => MessageAuthor::MEMBER,
                'body' => $body,
            ]);

            return $thread;
        });
    }

    public function append(Member $member, MessageThread $thread, string $body): Message
    {
        if ($thread->member_id !== $member->id) {
            throw new RuntimeException('This thread does not belong to the member.');
        }

        $body = trim($body);
        if ($body === '') {
            throw new RuntimeException('A message needs a body.');
        }

        return DB::transaction(function () use ($thread, $body): Message {
            $message = Message::create([
                'thread_id' => $thread->id,
                'author' => MessageAuthor::MEMBER,
                'body' => $body,
            ]);

            // Writing again reopens a closed thread — the conversation is not over.
            $thread->update([
                'status' => MessageThreadStatus::OPEN,
                'closed_at' => null,
                'closed_by' => null,
                'last_message_at' => now(),
            ]);

            return $message;
        });
    }

    private function primaryLocationId(Member $member): ?string
    {
        return $member->memberships()->withoutGlobalScopes()
            ->where('status', MembershipStatus::ACTIVE->value)
            ->value('location_id');
    }
}
