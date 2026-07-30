<?php

namespace Database\Factories;

use App\Models\Batch;
use App\Models\StockTake;
use App\Models\StockTakeLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockTakeLine>
 */
class StockTakeLineFactory extends Factory
{
    protected $model = StockTakeLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stock_take_id' => StockTake::factory(),
            'countable_type' => Batch::class,
            'countable_id' => Batch::factory(),
            'counted_cg' => fake()->numberBetween(0, 50000),
            'counted_units' => null,
            'expected_cg' => fake()->numberBetween(0, 50000),
            'expected_units' => null,
            'variance_cg' => fake()->numberBetween(-500, 500),
            'variance_units' => null,
        ];
    }
}
