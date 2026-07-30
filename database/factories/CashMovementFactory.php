<?php

namespace Database\Factories;

use App\Enums\CashMovementType;
use App\Models\CashMovement;
use App\Models\TillSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashMovement>
 */
class CashMovementFactory extends Factory
{
    protected $model = CashMovement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'till_session_id' => TillSession::factory(),
            'amount_cents' => fake()->numberBetween(500, 50000),
            'type' => CashMovementType::IN,
            'reason' => fake()->sentence(3),
            'operator_id' => null,
        ];
    }
}
