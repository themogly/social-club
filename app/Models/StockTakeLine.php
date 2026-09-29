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
        'stock_take_id', 'countable_type', 'countable_id',
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

    /** The difference in the line's own unit (centigrams, or units), from the snapshot taken when it was counted. */
    public function difference(): ?int
    {
        if ($this->not_counted || $this->counted_at === null) {
            return null;
        }

        return $this->isUnit()
            ? (int) $this->counted_units - (int) $this->expected_units
            : (int) $this->counted_cg?->centigrams - (int) $this->expected_cg?->centigrams;
    }
}
