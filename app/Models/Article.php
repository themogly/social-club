<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\StockMovementType;
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
        'organisation_id', 'location_id', 'group_id', 'name', 'category_id', 'price_cents',
        'stock', 'low_stock_threshold', 'images', 'active',
        'sold_at', // prompt 378 — 'BAR' | 'SHOP': which cash box its money goes in (snapshotted on each order item)
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
     * Prompt 297 — has this product a past at its sede? Its sales (orders naming it), any stock movement beyond the one
     * opening intake, or a stock count. A product with history never moves sede: moving it would move its past too and
     * rewrite both sedes' stock and sales reports. Queried live, never cached.
     */
    public function hasHistory(): bool
    {
        $movements = StockMovement::query()->withoutGlobalScopes()
            ->where('stockable_type', $this->getMorphClass())->where('stockable_id', $this->getKey());

        return (clone $movements)->where('type', '!=', StockMovementType::INTAKE->value)->exists()
            || (clone $movements)->count() > 1
            || Order::query()->withoutGlobalScopes()->containingArticle((string) $this->getKey())->exists()
            || StockTakeLine::query()->where('countable_type', $this->getMorphClass())->where('countable_id', $this->getKey())->exists();
    }

    /**
     * The same product at the group's other sedes (prompt 297), whatever the active sede.
     *
     * @return Builder<static>
     */
    public function groupSiblings(): Builder
    {
        return static::query()->withoutGlobalScopes()
            ->where('organisation_id', $this->organisation_id)
            ->where('group_id', $this->group_id ?? '')
            ->whereKeyNot($this->getKey());
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
