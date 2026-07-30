<?php

namespace Database\Factories;

use App\Enums\SanctionType;
use App\Models\Member;
use App\Models\MemberSanction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemberSanction>
 */
class MemberSanctionFactory extends Factory
{
    protected $model = MemberSanction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'type' => SanctionType::WARNING,
            'reason' => fake()->sentence(),
            'from_date' => now(),
            'until_date' => fake()->dateTimeBetween('now', '+1 month'),
            'recorded_by' => null,
        ];
    }
}
