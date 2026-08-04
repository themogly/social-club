<?php

namespace Database\Factories;

use App\Enums\ResolutionResult;
use App\Models\AssemblyResolution;
use App\Models\Convocatoria;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssemblyResolution>
 */
class AssemblyResolutionFactory extends Factory
{
    protected $model = AssemblyResolution::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'convocatoria_id' => Convocatoria::factory(),
            'position' => fake()->numberBetween(1, 10),
            'title' => fake()->sentence(4),
            'result' => ResolutionResult::APPROVED,
            'votes_for' => fake()->numberBetween(1, 50),
            'votes_against' => fake()->numberBetween(0, 10),
            'votes_abstain' => fake()->numberBetween(0, 5),
            'recorded_by' => null,
        ];
    }
}
