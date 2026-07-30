<?php

namespace Database\Factories;

use App\Enums\MembershipPeriod;
use App\Models\MembershipTier;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MembershipTier>
 */
class MembershipTierFactory extends Factory
{
    protected $model = MembershipTier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'name' => fake()->randomElement(['Standard', 'Premium', 'Therapeutic', 'Founder']),
            'default_fee_cents' => fake()->numberBetween(1000, 20000),
            'default_period' => MembershipPeriod::YEARLY,
            'benefits' => fake()->sentence(),
            'active' => true,
        ];
    }
}
