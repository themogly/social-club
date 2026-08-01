<?php

namespace Database\Factories;

use App\Enums\ConvocatoriaType;
use App\Models\Convocatoria;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Convocatoria>
 */
class ConvocatoriaFactory extends Factory
{
    protected $model = Convocatoria::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'location_id' => null,
            'type' => ConvocatoriaType::ORDINARY,
            'title' => fake()->sentence(4),
            'agenda' => ['Lectura del acta anterior', 'Aprobación de cuentas', 'Ruegos y preguntas'],
            'body' => fake()->paragraph(),
            'venue' => fake()->streetAddress(),
            'held_at' => now()->addDays(30),
            'second_call_at' => now()->addDays(30)->addMinutes(30),
            'notice_days' => null,
            'quorum_required' => null,
            'roll_count' => null,
            'issued_at' => null,
            'issued_by' => null,
            'created_by' => null,
        ];
    }
}
