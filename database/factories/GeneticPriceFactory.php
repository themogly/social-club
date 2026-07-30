<?php

namespace Database\Factories;

use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GeneticPrice>
 */
class GeneticPriceFactory extends Factory
{
    protected $model = GeneticPrice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'genetic_id' => Genetic::factory(),
            'location_id' => Location::factory(),
            'tier_id' => null,
            'price_per_gram_cents' => fake()->numberBetween(500, 1500),
            'low_stock_threshold_cg' => fake()->numberBetween(1000, 10000),
            'active' => true,
        ];
    }
}
