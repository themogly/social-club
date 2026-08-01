<?php

namespace Database\Factories;

use App\Enums\ConvocatoriaRecipientStatus;
use App\Models\Convocatoria;
use App\Models\ConvocatoriaRecipient;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConvocatoriaRecipient>
 */
class ConvocatoriaRecipientFactory extends Factory
{
    protected $model = ConvocatoriaRecipient::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $email = fake()->safeEmail();

        return [
            'convocatoria_id' => Convocatoria::factory(),
            'member_id' => Member::factory(),
            'member_no' => 'M-'.fake()->unique()->numberBetween(1000, 9999),
            'name' => fake()->name(),
            'email' => $email,
            'status' => ConvocatoriaRecipientStatus::NOTIFIED,
            'notified_at' => now(),
        ];
    }
}
