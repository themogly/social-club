<?php

namespace Database\Factories;

use App\Models\Announcement;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'location_id' => null,
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'published_at' => now(),
            'expires_at' => null,
            'author_id' => null,
        ];
    }
}
