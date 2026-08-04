<?php

namespace Database\Factories;

use App\Enums\MessageThreadStatus;
use App\Models\Member;
use App\Models\MessageThread;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageThread>
 */
class MessageThreadFactory extends Factory
{
    protected $model = MessageThread::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'member_id' => Member::factory(),
            'location_id' => null,
            'subject' => fake()->sentence(4),
            'status' => MessageThreadStatus::OPEN,
            'last_message_at' => now(),
            'closed_at' => null,
            'closed_by' => null,
            'data_request_id' => null,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (): array => ['status' => MessageThreadStatus::CLOSED, 'closed_at' => now()]);
    }
}
