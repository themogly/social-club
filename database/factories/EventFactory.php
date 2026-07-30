<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'location_id' => null,
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'starts_at' => fake()->dateTimeBetween('now', '+2 months'),
            'capacity' => fake()->numberBetween(10, 100),
        ];
    }
}
