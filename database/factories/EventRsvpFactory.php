<?php

namespace Database\Factories;

use App\Enums\EventRsvpStatus;
use App\Models\Event;
use App\Models\EventRsvp;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventRsvp>
 */
class EventRsvpFactory extends Factory
{
    protected $model = EventRsvp::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'member_id' => Member::factory(),
            'status' => EventRsvpStatus::GOING,
            'responded_at' => now(),
        ];
    }
}
