<?php

namespace Database\Factories;

use App\Enums\FeePaymentMethod;
use App\Models\Membership;
use App\Models\MembershipFeePayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MembershipFeePayment>
 */
class MembershipFeePaymentFactory extends Factory
{
    protected $model = MembershipFeePayment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'membership_id' => Membership::factory(),
            'amount_cents' => fake()->numberBetween(1000, 20000),
            'method' => FeePaymentMethod::CASH,
            'till_session_id' => null,
            'paid_at' => now(),
            'recorded_by' => null,
            'instalment_of' => null,
        ];
    }
}
