<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Casts\WeightCast;
use Database\Factories\DispensationLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DispensationLine extends Model
{
    /** @use HasFactory<DispensationLineFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'dispensation_id', 'genetic_id', 'batch_id', 'grams_cg', 'price_per_gram_cents',
        'discount_cents', 'line_total_cents', 'genetic_name_snapshot', 'batch_no_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'grams_cg' => WeightCast::class,
            'price_per_gram_cents' => 'integer',   // frozen rate
            'discount_cents' => MoneyCast::class,
            'line_total_cents' => MoneyCast::class,
        ];
    }

    /** @return BelongsTo<Dispensation, $this> */
    public function dispensation(): BelongsTo
    {
        return $this->belongsTo(Dispensation::class);
    }

    /** @return BelongsTo<Genetic, $this> */
    public function genetic(): BelongsTo
    {
        return $this->belongsTo(Genetic::class);
    }

    /** @return BelongsTo<Batch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
