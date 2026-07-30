<?php

namespace App\Models;

use App\Enums\CultivationType;
use App\Models\Concerns\BelongsToOrganisation;
use Database\Factories\GeneticFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Org-wide strain definition. Holds no price — prices are per location (GeneticPrice). */
class Genetic extends Model
{
    /** @use HasFactory<GeneticFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'organisation_id', 'name', 'description', 'category_id', 'thc_bp', 'cbd_bp',
        'terpenes', 'cultivation_type', 'images', 'published', 'active',
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
        ];
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
