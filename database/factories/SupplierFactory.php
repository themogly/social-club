<?php

namespace Database\Factories;

use App\Models\Organisation;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'name' => fake()->company(),
            'contact' => fake()->name(),
            'tax_id' => fake()->bothify('B########'),
            'notes' => fake()->sentence(),
            'active' => true,
        ];
    }
}
