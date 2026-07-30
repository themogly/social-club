<?php

namespace App\Models;

use App\Enums\CategoryAppliesTo;
use App\Models\Concerns\BelongsToOrganisation;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One category model for both catalogues (genetics + articles), so filters/reports behave the same. */
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids;

    protected $fillable = [
        'organisation_id', 'name', 'applies_to',
    ];

    protected function casts(): array
    {
        return [
            'applies_to' => CategoryAppliesTo::class,
        ];
    }

    /** @return HasMany<Genetic, $this> */
    public function genetics(): HasMany
    {
        return $this->hasMany(Genetic::class);
    }

    /** @return HasMany<Article, $this> */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }
}
