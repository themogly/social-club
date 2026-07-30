<?php

namespace App\Models;

use App\Enums\UnitType;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\ScopedToLocation;
use Database\Factories\GeneticPriceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Per-location price for a genetic. tier_id null = base price. Prompt 08 resolves
 * against this. Exactly ONE of price_per_gram_cents / price_per_unit_cents is
 * populated per row, driven by the genetic's unit_type (WEIGHT → per gram, UNIT →
 * per unit) — enforced by the saving guard, not by convention.
 */
class GeneticPrice extends Model
{
    /** @use HasFactory<GeneticPriceFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids, ScopedToLocation;

    protected $fillable = [
        'organisation_id', 'genetic_id', 'location_id', 'tier_id',
        'price_per_gram_cents', 'price_per_unit_cents', 'low_stock_threshold_cg', 'active',
    ];

    protected function casts(): array
    {
        return [
            'price_per_gram_cents' => 'integer',   // rate, not an amount
            'price_per_unit_cents' => 'integer',   // rate, not an amount
            'low_stock_threshold_cg' => 'integer',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // One-of-two: the populated price column must match the genetic's unit_type.
        static::saving(function (GeneticPrice $price): void {
            $genetic = Genetic::withoutGlobalScopes()->whereKey($price->genetic_id)->first(['unit_type']);
            if ($genetic === null) {
                return; // genetic not resolvable yet — nothing to enforce against
            }

            $perGram = $price->price_per_gram_cents;
            $perUnit = $price->price_per_unit_cents;

            if ($genetic->unit_type === UnitType::UNIT) {
                if ($perUnit === null || $perGram !== null) {
                    throw new RuntimeException('A per-unit genetic price must set price_per_unit_cents and leave price_per_gram_cents null.');
                }
            } elseif ($perGram === null || $perUnit !== null) {
                throw new RuntimeException('A by-weight genetic price must set price_per_gram_cents and leave price_per_unit_cents null.');
            }
        });
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
