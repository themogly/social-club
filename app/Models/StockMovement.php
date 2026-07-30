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

/** Every gram/unit movement. Opening balances enter here as INTAKE — never free-typed. */
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
