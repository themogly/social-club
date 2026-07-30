<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\ScopedToLocation;
use Database\Factories\GeneticPriceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Per-location price for a genetic. tier_id null = base price. Prompt 08 resolves against this. */
class GeneticPrice extends Model
{
    /** @use HasFactory<GeneticPriceFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids, ScopedToLocation;

    protected $fillable = [
        'organisation_id', 'genetic_id', 'location_id', 'tier_id',
        'price_per_gram_cents', 'low_stock_threshold_cg', 'active',
    ];

    protected function casts(): array
    {
        return [
            'price_per_gram_cents' => 'integer',   // rate, not an amount
            'low_stock_threshold_cg' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Genetic, $this> */
    public function genetic(): BelongsTo
    {
        return $this->belongsTo(Genetic::class);
    }

    /** @return BelongsTo<MembershipTier, $this> */
    public function tier(): BelongsTo
    {
        return $this->belongsTo(MembershipTier::class, 'tier_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
