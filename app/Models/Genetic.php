<?php

namespace App\Models;

use App\Enums\ConcentrateSubtype;
use App\Enums\CultivationType;
use App\Enums\ProductType;
use App\Enums\UnitType;
use App\Models\Concerns\BelongsToOrganisation;
use App\Observers\GeneticObserver;
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
        'product_type', 'concentrate_subtype', 'grams_per_unit_cg', 'thc_mg_per_unit',
        // 'unit_type' is deliberately NOT fillable — GeneticObserver derives + stores it.
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
}
