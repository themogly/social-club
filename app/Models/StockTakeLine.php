<?php

namespace App\Models;

use App\Casts\WeightCast;
use Database\Factories\StockTakeLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockTakeLine extends Model
{
    /** @use HasFactory<StockTakeLineFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'stock_take_id', 'countable_type', 'countable_id',
        'counted_cg', 'counted_units', 'expected_cg', 'expected_units', 'variance_cg', 'variance_units',
        'not_counted', 'not_counted_reason',
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
}
