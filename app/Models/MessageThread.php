<?php

namespace App\Models;

use App\Enums\MessageAuthor;
use App\Enums\MessageThreadStatus;
use App\Models\Concerns\BelongsToOrganisation;
use Database\Factories\MessageThreadFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A member↔club conversation. Belongs to one member; its messages are authored by the member or the club.
 * A contact channel, never an ordering channel. Written only through App\Actions\Messaging\* — the member
 * side starts/appends via SendMemberMessage, the club side replies via ReplyToThread. Once converted to an
 * RGPD data request it carries data_request_id as the evidence link.
 */
class MessageThread extends Model
{
    /** @use HasFactory<MessageThreadFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'organisation_id', 'member_id', 'location_id', 'subject', 'status',
        'last_message_at', 'closed_at', 'closed_by', 'data_request_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => MessageThreadStatus::class,
            'last_message_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** True when the last word was the member's — the club owes a reply. */
    public function awaitingReply(): bool
    {
        return $this->messages()->latest('created_at')->value('author') === MessageAuthor::MEMBER->value;
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** @return BelongsTo<DataRequest, $this> */
    public function dataRequest(): BelongsTo
    {
        return $this->belongsTo(DataRequest::class);
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'thread_id');
    }
}
