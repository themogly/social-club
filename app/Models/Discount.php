<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\DiscountAppliesTo;
use App\Enums\DiscountKind;
use App\Enums\DiscountMode;
use App\Models\Concerns\BelongsToOrganisation;
use Database\Factories\DiscountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Org-wide discount template, enabled per location via the discount_location pivot. */
class Discount extends Model
{
    /** @use HasFactory<DiscountFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'organisation_id', 'name', 'kind', 'mode', 'value_bp', 'value_cents',
        'applies_to', 'category_id', 'active',
    ];

    protected function casts(): array
    {
        return [
            'kind' => DiscountKind::class,
            'mode' => DiscountMode::class,
            'value_bp' => 'integer',
            'value_cents' => MoneyCast::class,
            'applies_to' => DiscountAppliesTo::class,
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsToMany<Location, $this> */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class)->withTimestamps();
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
