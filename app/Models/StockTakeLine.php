<?php

namespace App\Models;

use App\Casts\WeightCast;
use App\Enums\StockCountReason;
use Database\Factories\StockTakeLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of a stock take (the till's recount, or an *Inventario* — prompt 318).
 *
 * @property string $countable_type Batch | Article (ulidMorphs, which Larastan does not read)
 * @property string $countable_id
 */
class StockTakeLine extends Model
{
    /** @use HasFactory<StockTakeLineFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'stock_take_id', 'countable_type', 'countable_id', 'optional', 'unrecorded_topup_cg',
        'expected_reserve_cg', 'counted_reserve_cg', 'variance_reserve_cg', // prompt 360 — the sealed reserve, on the same row
        'counted_cg', 'counted_units', 'expected_cg', 'expected_units', 'variance_cg', 'variance_units',
        'not_counted', 'not_counted_reason',
        'counted_by', 'counted_at', 'adjustment_reason', 'adjustment_note', // prompt 318
    ];

    protected function casts(): array
    {
        return [
            'counted_cg' => WeightCast::class,
            'counted_units' => 'integer',
            'expected_cg' => WeightCast::class,
            'expected_units' => 'integer',
            'variance_cg' => WeightCast::class,
            'variance_units' => 'integer',
            'not_counted' => 'boolean',
            'optional' => 'boolean', // prompt 360 — listed by «Incluir lotes a cero»: untouched, it holds nothing up
            'expected_reserve_cg' => WeightCast::class, // prompt 360 — the batch's «Reserva sellada» when counted
            'counted_reserve_cg' => WeightCast::class,
            'variance_reserve_cg' => WeightCast::class,
            'unrecorded_topup_cg' => WeightCast::class, // prompt 359 — a forgotten «Rellenar» the close count absorbed
            'counted_at' => 'datetime',
            'adjustment_reason' => StockCountReason::class,
        ];
    }

    /** @return BelongsTo<StockTake, $this> */
    public function stockTake(): BelongsTo
    {
        return $this->belongsTo(StockTake::class);
    }

    /** @return MorphTo<Model, $this> */
    public function countable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    /** Is this line done — counted, or marked *No contado* with its reason? */
    public function isSettled(): bool
    {
        return $this->not_counted || $this->counted_at !== null;
    }

    public function isUnit(): bool
    {
        return $this->countable_type === Article::class || ($this->countable instanceof Batch && $this->countable->isUnitType());
    }

    /** Does this row count a sealed reserve as well as the jar? Weight batches only (prompt 360; units keep one count). */
    public function countsReserve(): bool
    {
        return $this->countable_type === Batch::class && ! $this->isUnit();
    }

    /**
     * The difference in the line's own unit (centigrams, or units), from the snapshot taken when it was counted. For a
     * weight batch this is the JAR's; null when the jar was left blank (prompt 360: blank = untouched).
     */
    public function difference(): ?int
    {
        if ($this->not_counted || $this->counted_at === null) {
            return null;
        }
        if ($this->isUnit()) {
            return (int) $this->counted_units - (int) $this->expected_units;
        }

        return $this->counted_cg === null ? null : $this->counted_cg->centigrams - (int) $this->expected_cg?->centigrams;
    }

    /** Prompt 360 — the sealed reserve's difference in centigrams; null when it was left blank. */
    public function reserveDifference(): ?int
    {
        if ($this->not_counted || $this->counted_at === null || $this->counted_reserve_cg === null) {
            return null;
        }

        return $this->counted_reserve_cg->centigrams - (int) $this->expected_reserve_cg?->centigrams;
    }

    /** Prompt 360 — an empty batch listed by «Incluir lotes a cero» and never touched: skipped, never "pending". */
    public function isSkippable(): bool
    {
        return $this->optional && ! $this->isSettled();
    }
}
