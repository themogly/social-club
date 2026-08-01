<?php

namespace App\Models;

use App\Enums\BatchStatus;
use App\Enums\ConcentrateSubtype;
use App\Enums\CultivationType;
use App\Enums\ProductType;
use App\Enums\StrainType;
use App\Enums\UnitType;
use App\Models\Concerns\BelongsToOrganisation;
use App\Observers\GeneticObserver;
use App\Support\Settings;
use Database\Factories\GeneticFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Org-wide strain definition. Holds no price — prices are per location (GeneticPrice).
 * `product_type` drives a derived, stored `unit_type` (WEIGHT|UNIT) via GeneticObserver;
 * downstream code branches on `unit_type`, never `product_type`. `unit_type` is NOT
 * fillable — it is observer-set, never mass-assigned.
 */
#[ObservedBy([GeneticObserver::class])]
class Genetic extends Model
{
    /** @use HasFactory<GeneticFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'organisation_id', 'name', 'description', 'category_id', 'thc_bp', 'cbd_bp',
        'terpenes', 'cultivation_type', 'images', 'published', 'active',
        'product_type', 'concentrate_subtype', 'grams_per_unit_cg', 'thc_mg_per_unit', 'strain_type',
        // 'unit_type' is deliberately NOT fillable — GeneticObserver derives + stores it. strain_type
        // IS fillable (prompt 66) — a user-set property, not derived.
    ];

    protected function casts(): array
    {
        return [
            'thc_bp' => 'integer',
            'cbd_bp' => 'integer',
            'terpenes' => 'array',
            'cultivation_type' => CultivationType::class,
            'images' => 'array',
            'published' => 'boolean',
            'active' => 'boolean',
            'product_type' => ProductType::class,
            'strain_type' => StrainType::class,
            'unit_type' => UnitType::class,
            'concentrate_subtype' => ConcentrateSubtype::class,
            'grams_per_unit_cg' => 'integer',
            'thc_mg_per_unit' => 'integer',
        ];
    }

    /** True when this genetic is dispensed in whole units (preroll/edible), not by weight. */
    public function isUnitType(): bool
    {
        return $this->unit_type === UnitType::UNIT;
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<GeneticPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(GeneticPrice::class);
    }

    /** @return HasMany<Batch, $this> */
    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('published', true);
    }

    // --- Derived completeness (prompt 93) — NEVER stored. A genetic can be Active + Published and still
    //     appear NOWHERE at the counter, because dispensing needs, per location, an active base price AND
    //     stock. These derive that truth live so the list and the record can explain the gap.

    /** Has an active base (non-tier) price at this location — the exact condition the POS filters on. */
    public function hasActivePriceAt(string $locationId): bool
    {
        return $this->prices()->withoutGlobalScopes()
            ->where('location_id', $locationId)->whereNull('tier_id')->where('active', true)->exists();
    }

    /** Has an active base price at ANY location (i.e. it can be dispensed somewhere). */
    public function hasAnyActivePrice(): bool
    {
        return $this->prices()->withoutGlobalScopes()->whereNull('tier_id')->where('active', true)->exists();
    }

    /** Has an OPEN batch with stock at this location (weight or units). */
    public function hasStockAt(string $locationId): bool
    {
        return $this->batches()->withoutGlobalScopes()
            ->where('location_id', $locationId)->where('status', BatchStatus::OPEN->value)
            ->where(fn (Builder $q) => $q->where('remaining_cg', '>', 0)->orWhere('remaining_units', '>', 0))->exists();
    }

    public function hasAnyStock(): bool
    {
        return $this->batches()->withoutGlobalScopes()->where('status', BatchStatus::OPEN->value)
            ->where(fn (Builder $q) => $q->where('remaining_cg', '>', 0)->orWhere('remaining_units', '>', 0))->exists();
    }

    /**
     * The ONE definition of "sellable at a sede" (prompt 95): active, and with an active base price at that
     * location. The dispensary POS and the member menu both scope through this, so the two surfaces can never
     * disagree on what is available — and an unpriced strain is simply FILTERED OUT, never a thrown error
     * that takes a page down. Soft-deleted genetics are excluded because this does not touch that scope.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSellableAt(Builder $query, string $locationId): Builder
    {
        return $query->where('active', true)
            ->whereHas('prices', fn (Builder $q) => $q->withoutGlobalScopes()
                ->where('location_id', $locationId)
                ->whereNull('tier_id')
                ->where('active', true));
    }

    /**
     * Why this genetic cannot yet be dispensed anywhere — 'no_price' (needs a per-location price),
     * 'no_stock' (priced but no batch), or null when it is ready. Derived live, never stored.
     */
    public function completenessReason(): ?string
    {
        if (! $this->hasAnyActivePrice()) {
            return 'no_price';
        }
        if (! $this->hasAnyStock()) {
            return 'no_stock';
        }

        return null;
    }

    /**
     * The low-stock threshold (gram-equivalent centigrams) for this genetic at a location (prompt 54):
     * the base GeneticPrice row's `low_stock_threshold_cg` at that sede, else the org-wide
     * `low_stock_threshold_cg` setting. The consumer of the threshold + fallback that had none before.
     */
    public function lowStockThresholdCg(?string $locationId): int
    {
        $perLocation = $this->prices()->withoutGlobalScopes()
            ->where('location_id', $locationId)
            ->whereNull('tier_id')
            ->value('low_stock_threshold_cg');

        return (int) ($perLocation ?? Settings::get('low_stock_threshold_cg', 5000));
    }

    /**
     * Low stock when the on-hand gram-equivalent is at or below the resolved threshold (mirrors the
     * Article `stock <= low_stock_threshold` rule). For a UNIT genetic the caller passes the gram
     * equivalent (units × grams_per_unit_cg), so one comparison serves both weight and unit genetics.
     */
    public function isLowStockAt(int $remainingCg, ?string $locationId): bool
    {
        return $remainingCg <= $this->lowStockThresholdCg($locationId);
    }
}
