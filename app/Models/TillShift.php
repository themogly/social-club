<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\TillShiftStatus;
use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who held the drawer, and what they left in it (prompt 186).
 *
 * A cash variance is attributable to whoever held the drawer. Before this, a shift change either produced
 * two arqueos for one trading day or left a shortfall belonging to nobody. A shift is the attributable
 * unit; the session remains the trading day and the arqueo.
 *
 * Immutable once closed, like the session: a correction is a new entry, never an edit.
 */
class TillShift extends Model
{
    use BelongsToOrganisation, HasUlids;

    protected $fillable = [
        'organisation_id', 'till_session_id', 'opened_by', 'opened_at',
        'opening_counted_cents', 'opening_expected_cents',
        'closed_by', 'closed_at', 'counted_cents', 'expected_cents', 'variance_cents', 'notes', 'status',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_counted_cents' => MoneyCast::class,
            'opening_expected_cents' => MoneyCast::class,
            'counted_cents' => MoneyCast::class,
            'expected_cents' => MoneyCast::class,
            'variance_cents' => MoneyCast::class,
            'status' => TillShiftStatus::class,
        ];
    }

    protected static function booted(): void
    {
        // Immutable once handed over — the same guarantee the session's arqueo has. A shift that could be
        // edited after the fact would make its variance worth nothing, which is the whole point of the table.
        static::updating(function (TillShift $shift): void {
            if ($shift->getRawOriginal('status') === TillShiftStatus::CLOSED->value) {
                throw new \RuntimeException('A closed till shift is immutable — record a correction instead.');
            }
        });
    }

    /** @return BelongsTo<TillSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TillSession::class, 'till_session_id');
    }

    /** @return BelongsTo<User, $this> */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @param  Builder<TillShift>  $query
     * @return Builder<TillShift>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', TillShiftStatus::OPEN->value);
    }
}
