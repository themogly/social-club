<?php

namespace Database\Factories;

use App\Enums\DiscountAppliesTo;
use App\Enums\DiscountKind;
use App\Enums\DiscountMode;
use App\Models\Discount;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Discount>
 */
class DiscountFactory extends Factory
{
    protected $model = Discount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'name' => fake()->words(2, true),
            'kind' => DiscountKind::STAFF,
            'mode' => DiscountMode::PERCENT,
            'value_bp' => fake()->numberBetween(500, 5000),
            'value_cents' => null,
            'applies_to' => DiscountAppliesTo::BOTH,
            'category_id' => null,
            'active' => true,
        ];
    }
}
