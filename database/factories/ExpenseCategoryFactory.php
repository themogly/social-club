<?php

namespace Database\Factories;

use App\Enums\ExpenseKind;
use App\Models\ExpenseCategory;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseCategory>
 */
class ExpenseCategoryFactory extends Factory
{
    protected $model = ExpenseCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'name' => fake()->words(2, true),
            'default_kind' => ExpenseKind::OVERHEAD,
            'active' => true,
        ];
    }
}
