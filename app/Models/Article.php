<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\ScopedToLocation;
use Database\Factories\ArticleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Bar/food/merch item, per location. Its own catalogue + ledger (separate from contributions). */
class Article extends Model
{
    /** @use HasFactory<ArticleFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids, ScopedToLocation, SoftDeletes;

    protected $fillable = [
        'organisation_id', 'location_id', 'name', 'category_id', 'price_cents',
        'stock', 'low_stock_threshold', 'images', 'active',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => MoneyCast::class,
            'stock' => 'integer',
            'low_stock_threshold' => 'integer',
            'images' => 'array',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
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
    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereNotNull('low_stock_threshold')
            ->whereColumn('stock', '<=', 'low_stock_threshold');
    }
}
