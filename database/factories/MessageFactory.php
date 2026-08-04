<?php

namespace Database\Factories;

use App\Enums\MessageAuthor;
use App\Models\Message;
use App\Models\MessageThread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'thread_id' => MessageThread::factory(),
            'author' => MessageAuthor::MEMBER,
            'user_id' => null,
            'body' => fake()->paragraph(),
            'read_at' => null,
        ];
    }

    public function fromStaff(): static
    {
        return $this->state(fn (): array => ['author' => MessageAuthor::STAFF]);
    }
}
