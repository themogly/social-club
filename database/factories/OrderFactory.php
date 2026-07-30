<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Invariant: cash_cents + wallet_cents must equal total_cents.
        $total = fake()->numberBetween(500, 20000);

        return [
            'organisation_id' => Organisation::factory(),
            'location_id' => Location::factory(),
            'member_id' => null,
            'operator_id' => null,
            'till_session_id' => null,
            'items' => [],
            'total_cents' => $total,
            'cash_cents' => $total,
            'wallet_cents' => 0,
            'status' => OrderStatus::COMPLETED,
            'reversal_of_id' => null,
            'void_reason' => null,
            'voided_by' => null,
            'voided_at' => null,
            'idempotency_key' => fake()->uuid(),
            'reference' => fake()->bothify('ORD-#####'),
        ];
    }
}
