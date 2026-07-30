<?php

namespace App\Models;

use App\Enums\ExpenseKind;
use App\Models\Concerns\BelongsToOrganisation;
use Database\Factories\ExpenseCategoryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends Model
{
    /** @use HasFactory<ExpenseCategoryFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids;

    protected $fillable = [
        'organisation_id', 'name', 'default_kind', 'active',
    ];

    protected function casts(): array
    {
        return [
            'default_kind' => ExpenseKind::class,
            'active' => 'boolean',
        ];
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'category_id');
    }
}
