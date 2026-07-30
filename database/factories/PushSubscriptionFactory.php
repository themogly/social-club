<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\PushSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PushSubscription>
 */
class PushSubscriptionFactory extends Factory
{
    protected $model = PushSubscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscribable_type' => Member::class,
            'subscribable_id' => Member::factory(),
            'endpoint' => fake()->url(),
            'public_key' => fake()->sha256(),
            'auth_token' => fake()->sha256(),
            'content_encoding' => 'aesgcm',
        ];
    }
}
