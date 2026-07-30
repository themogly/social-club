<?php

namespace App\Models;

use App\Enums\EventRsvpStatus;
use Database\Factories\EventRsvpFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRsvp extends Model
{
    /** @use HasFactory<EventRsvpFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'event_id', 'member_id', 'status', 'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => EventRsvpStatus::class,
            'responded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
