<?php

namespace Database\Factories;

use App\Enums\CategoryAppliesTo;
use App\Models\Category;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'name' => fake()->word(),
            'applies_to' => CategoryAppliesTo::GENETIC,
        ];
    }
}
