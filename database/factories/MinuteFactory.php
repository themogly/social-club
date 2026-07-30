<?php

namespace Database\Factories;

use App\Enums\MinuteBook;
use App\Models\Minute;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Minute>
 */
class MinuteFactory extends Factory
{
    protected $model = Minute::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'book' => MinuteBook::ASSEMBLY,
            'number' => fake()->numberBetween(1, 9999),
            'type' => fake()->randomElement(['ordinary', 'extraordinary']),
            'held_on' => now(),
            'location_id' => null,
            'agenda' => [],
            'resolutions' => [],
            'attendees' => [],
            'quorum_present' => fake()->numberBetween(5, 50),
            'quorum_required' => fake()->numberBetween(3, 10),
            'body' => fake()->paragraphs(2, true),
            'signed_at' => null,
            'supersedes_id' => null,
        ];
    }
}
