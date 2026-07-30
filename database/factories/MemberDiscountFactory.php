<?php

namespace Database\Factories;

use App\Enums\DiscountMode;
use App\Models\Discount;
use App\Models\Member;
use App\Models\MemberDiscount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemberDiscount>
 */
class MemberDiscountFactory extends Factory
{
    protected $model = MemberDiscount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'discount_id' => Discount::factory(),
            'mode' => DiscountMode::PERCENT,
            'value_bp' => fake()->numberBetween(500, 5000),
            'value_cents' => null,
            'assigned_by' => null,
            'expires_at' => null,
        ];
    }
}
