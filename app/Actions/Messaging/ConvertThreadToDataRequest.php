<?php

namespace App\Actions\Messaging;

use App\Actions\RecordAuditLog;
use App\Enums\DataRequestType;
use App\Enums\MessageThreadStatus;
use App\Models\DataRequest;
use App\Models\MessageThread;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turn a message thread into a formal RGPD data request (prompt 136). The moment a member asks in words for
 * their data or its erasure, it stops being a message that can be forgotten and becomes a LOGGED obligation
 * in the subject-rights register — where the response clock and the completion evidence live. This only
 * RECORDS the request (a comms manager may do it); FULFILLING it stays the owner-gated DataRequest flow
 * (data.request.handle / data.erase), unchanged. The thread keeps the evidence link + is closed.
 */
class ConvertThreadToDataRequest
{
    public function handle(MessageThread $thread, User $actor, DataRequestType $type, ?string $notes = null): DataRequest
    {
        if (! $actor->can('comms.manage')) {
            throw new AuthorizationException('Converting a thread requires the comms.manage permission.');
        }

        if ($thread->member_id === null) {
            throw new RuntimeException('A thread with no member cannot become a data request.');
        }

        if ($thread->data_request_id !== null) {
            throw new RuntimeException('This thread has already been converted to a data request.');
        }

        return DB::transaction(function () use ($thread, $actor, $type, $notes): DataRequest {
            $dataRequest = DataRequest::create([
                'organisation_id' => $thread->organisation_id,
                'member_id' => $thread->member_id,
                'type' => $type,
                'requested_at' => $thread->created_at ?? now(),
                'notes' => $notes,
            ]);

            $thread->update([
                'data_request_id' => $dataRequest->id,
                'status' => MessageThreadStatus::CLOSED,
                'closed_at' => now(),
                'closed_by' => $actor->id,
            ]);

            (new RecordAuditLog)->handle('message_thread.converted_to_data_request', $dataRequest, null, [
                'thread_id' => $thread->id,
                'type' => $type->value,
            ]);

            return $dataRequest;
        });
    }
}
