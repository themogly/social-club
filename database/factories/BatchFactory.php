<?php

namespace Database\Factories;

use App\Enums\BatchStatus;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Batch>
 */
class BatchFactory extends Factory
{
    protected $model = Batch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'genetic_id' => Genetic::factory(),
            'location_id' => Location::factory(),
            'batch_no' => fake()->bothify('B-####'),
            'acquired_or_harvested_on' => fake()->dateTimeBetween('-1 year', 'now'),
            'expires_on' => fake()->dateTimeBetween('now', '+1 year'),
            'initial_cg' => fake()->numberBetween(10000, 500000),
            'remaining_cg' => fake()->numberBetween(0, 10000),
            'cost_per_gram_cents' => fake()->numberBetween(300, 1200),
            'lab_report_path' => null,
            'notes' => fake()->sentence(),
            'status' => BatchStatus::OPEN,
        ];
    }
}
