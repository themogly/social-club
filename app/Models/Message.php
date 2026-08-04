<?php

namespace App\Models;

use App\Enums\MessageAuthor;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a thread, authored by the MEMBER or by STAFF (the club). The body is free text — never a
 * product/quantity, because this is not an ordering channel. A member-authored body is PII, redacted on
 * anonymisation (via AnonymiseMember, keyed through the thread's member_id). Org scope is derived from the
 * parent thread, so this model carries no organisation_id of its own.
 */
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['thread_id', 'author', 'user_id', 'body', 'read_at'];

    protected function casts(): array
    {
        return [
            'author' => MessageAuthor::class,
            'read_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MessageThread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(MessageThread::class, 'thread_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
