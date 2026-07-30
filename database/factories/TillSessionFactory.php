<?php

namespace Database\Factories;

use App\Enums\TillSessionStatus;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\TillSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TillSession>
 */
class TillSessionFactory extends Factory
{
    protected $model = TillSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'location_id' => Location::factory(),
            'terminal' => fake()->bothify('TILL-##'),
            'opened_by' => null,
            'opened_at' => now(),
            'float_cents' => fake()->numberBetween(0, 50000),
            'closed_by' => null,
            'closed_at' => null,
            'counted_cents' => null,
            'expected_cents' => null,
            'variance_cents' => null,
            'status' => TillSessionStatus::OPEN,
            'notes' => null,
        ];
    }
}
