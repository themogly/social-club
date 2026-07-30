<?php

namespace Database\Factories;

use App\Enums\StockMovementType;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'location_id' => Location::factory(),
            'stockable_type' => Batch::class,
            'stockable_id' => Batch::factory(),
            'qty_cg' => fake()->numberBetween(100, 50000),
            'qty_units' => null,
            'type' => StockMovementType::INTAKE,
            'reason' => fake()->sentence(3),
            'operator_id' => null,
            'reference' => fake()->bothify('SM-#####'),
            'stock_take_id' => null,
        ];
    }
}
