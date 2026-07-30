<?php

namespace Database\Factories;

use App\Enums\BreachStatus;
use App\Models\BreachLog;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BreachLog>
 */
class BreachLogFactory extends Factory
{
    protected $model = BreachLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'description' => fake()->paragraph(),
            'discovered_at' => now(),
            'scope' => fake()->sentence(3),
            'aepd_notified_at' => null,
            'status' => BreachStatus::OPEN,
        ];
    }
}
