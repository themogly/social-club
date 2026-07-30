<?php

namespace App\Models;

use App\Casts\WeightCast;
use App\Enums\StockMovementType;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\ScopedToLocation;
use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * Every gram/unit movement. Opening balances enter here as INTAKE — never free-typed.
 * Exactly ONE of qty_cg / qty_units is populated (a WEIGHT-type Batch writes qty_cg; a
 * UNIT-type Batch or an Article writes qty_units) — enforced by the saving guard.
 */
class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids, ScopedToLocation;

    protected $fillable = [
        'organisation_id', 'location_id', 'stockable_type', 'stockable_id',
        'qty_cg', 'qty_units', 'type', 'reason', 'operator_id', 'reference', 'stock_take_id',
    ];

    protected function casts(): array
    {
        return [
            'qty_cg' => WeightCast::class,
            'qty_units' => 'integer',
            'type' => StockMovementType::class,
        ];
    }

    protected static function booted(): void
    {
        // One-of-two: a movement is either in centigrams or in units, never both, never neither.
        static::saving(function (StockMovement $movement): void {
            $attrs = $movement->getAttributes();
            $hasCg = ($attrs['qty_cg'] ?? null) !== null;
            $hasUnits = ($attrs['qty_units'] ?? null) !== null;

            if ($hasCg === $hasUnits) {
                throw new RuntimeException('A stock movement must set exactly one of qty_cg or qty_units.');
            }
        });
    }

    /** @return MorphTo<Model, $this> */
    public function stockable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    /** @return BelongsTo<StockTake, $this> */
    public function stockTake(): BelongsTo
    {
        return $this->belongsTo(StockTake::class);
    }
}
