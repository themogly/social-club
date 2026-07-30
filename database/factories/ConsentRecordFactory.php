<?php

namespace Database\Factories;

use App\Models\ConsentRecord;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsentRecord>
 */
class ConsentRecordFactory extends Factory
{
    protected $model = ConsentRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'purpose' => fake()->randomElement(['data_processing', 'marketing', 'medical']),
            'consent_text_version' => 'v'.fake()->numberBetween(1, 5),
            'granted_at' => now(),
            'withdrawn_at' => null,
            'ip' => fake()->ipv4(),
        ];
    }
}
