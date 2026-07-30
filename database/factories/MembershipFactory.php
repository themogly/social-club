<?php

namespace Database\Factories;

use App\Enums\MembershipStatus;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    protected $model = Membership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'member_id' => Member::factory(),
            'location_id' => Location::factory(),
            'tier_id' => MembershipTier::factory(),
            'starts_at' => now(),
            'expires_at' => fake()->dateTimeBetween('+1 month', '+1 year'),
            'fee_cents' => fake()->numberBetween(1000, 20000),
            'fee_override_by' => null,
            'status' => MembershipStatus::ACTIVE,
        ];
    }
}
