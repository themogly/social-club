<?php

namespace App\Models;

use App\Enums\StaffClockSource;
use App\Enums\StaffClockType;
use App\Models\Builders\AppendOnlyBuilder;
use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder;
use RuntimeException;

/**
 * One registro de jornada event (prompt 281, Ben's 280) — APPEND-ONLY. A saved event can never be updated or deleted,
 * through the model or through a mass `query()->update()/delete()` (the custom builder refuses both). A correction is a
 * NEW row: a manager's added IN/OUT, a self-declared end, or an ANNUL pointing at the event it cancels. The coming
 * digital-record decree requires exactly this (personal, unalterable); retrofitting it later would be impossible.
 * Raw `DB::table()` writes bypass Eloquent entirely and cannot be guarded here — nothing in the app uses them on this table.
 */
// Mass `query()->update()/delete()` is refused too — model events alone would not catch it.
#[UseEloquentBuilder(AppendOnlyBuilder::class)]
class StaffClockEvent extends Model
{
    use BelongsToOrganisation, HasUlids;

    protected $fillable = [
        'organisation_id', 'user_id', 'location_id', 'type', 'occurred_at', 'recorded_at', 'business_date',
        'source', 'recorded_by', 'corrects_event_id', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'type' => StaffClockType::class,
            'source' => StaffClockSource::class,
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'business_date' => 'immutable_date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('A clock event is immutable — record a correction instead.'));
        static::deleting(fn () => throw new RuntimeException('A clock event is never deleted — annul it instead.'));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }

    /** @return BelongsTo<StaffClockEvent, $this> */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(StaffClockEvent::class, 'corrects_event_id');
    }
}
