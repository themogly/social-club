<?php

namespace Database\Factories;

use App\Enums\CultivationType;
use App\Models\Genetic;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Genetic>
 */
class GeneticFactory extends Factory
{
    protected $model = Genetic::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'name' => fake()->words(2, true),
            'description' => fake()->paragraph(),
            'category_id' => null,
            'thc_bp' => fake()->numberBetween(0, 3000),
            'cbd_bp' => fake()->numberBetween(0, 2000),
            'terpenes' => [],
            'cultivation_type' => CultivationType::INDOOR,
            'images' => [],
            'published' => true,
            'active' => true,
        ];
    }
}
