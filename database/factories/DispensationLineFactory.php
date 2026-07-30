<?php

namespace Database\Factories;

use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\DispensationLine;
use App\Models\Genetic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DispensationLine>
 */
class DispensationLineFactory extends Factory
{
    protected $model = DispensationLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dispensation_id' => Dispensation::factory(),
            'genetic_id' => Genetic::factory(),
            'batch_id' => Batch::factory(),
            'grams_cg' => fake()->numberBetween(100, 5000),
            'price_per_gram_cents' => fake()->numberBetween(500, 1500),
            'discount_cents' => fake()->numberBetween(0, 500),
            'line_total_cents' => fake()->numberBetween(500, 20000),
            'genetic_name_snapshot' => fake()->words(2, true),
            'batch_no_snapshot' => fake()->bothify('B-####'),
        ];
    }
}
