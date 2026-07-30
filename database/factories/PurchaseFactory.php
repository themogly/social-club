<?php

namespace Database\Factories;

use App\Models\Organisation;
use App\Models\Purchase;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Purchase>
 */
class PurchaseFactory extends Factory
{
    protected $model = Purchase::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'location_id' => null,
            'supplier_id' => Supplier::factory(),
            'amount_cents' => fake()->numberBetween(5000, 500000),
            'paid_cents' => fake()->numberBetween(0, 5000),
            'items' => [],
            'invoice_path' => null,
            'purchased_on' => now(),
            'batch_id' => null,
        ];
    }
}
