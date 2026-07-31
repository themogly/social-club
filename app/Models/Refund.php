<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Casts\WeightCast;
use App\Enums\RefundDestination;
use App\Enums\RefundMethod;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\ScopedToLocation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A refund against a completed dispensation (prompt 65) — append-only, never a mutation of the original.
 * Partial and repeated refunds are allowed; RefundDispensation guarantees their cumulative amount and
 * weight never exceed the original. The single writer is App\Actions\Dispensing\RefundDispensation — this
 * model is never hand-built (no factory), so its shape can never drift from the writer.
 */
class Refund extends Model
{
    use BelongsToOrganisation, HasUlids, ScopedToLocation;

    /** @var array<string, int> Money/weight columns are non-null (DB default 0); mirror that in memory. */
    protected $attributes = [
        'amount_cents' => 0,
        'grams_cg' => 0,
    ];

    protected $fillable = [
        'organisation_id', 'location_id', 'dispensation_id', 'member_id',
        'amount_cents', 'grams_cg', 'destination', 'method',
        'batch_id', 'till_session_id', 'reason', 'refunded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => MoneyCast::class,
            'grams_cg' => WeightCast::class,
            'destination' => RefundDestination::class,
            'method' => RefundMethod::class,
        ];
    }

    /** @return BelongsTo<Dispensation, $this> */
    public function dispensation(): BelongsTo
    {
        return $this->belongsTo(Dispensation::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<Batch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /** @return BelongsTo<TillSession, $this> */
    public function tillSession(): BelongsTo
    {
        return $this->belongsTo(TillSession::class);
    }

    /** @return BelongsTo<User, $this> */
    public function refundedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }
}
