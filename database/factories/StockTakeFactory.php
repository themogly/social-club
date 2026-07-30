<?php

namespace Database\Factories;

use App\Enums\StockTakeStatus;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\StockTake;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockTake>
 */
class StockTakeFactory extends Factory
{
    protected $model = StockTake::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'location_id' => Location::factory(),
            'opened_by' => null,
            'opened_at' => now(),
            'committed_by' => null,
            'committed_at' => null,
            'status' => StockTakeStatus::OPEN,
            'notes' => fake()->sentence(),
        ];
    }
}
