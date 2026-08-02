<?php

namespace Database\Factories;

use App\Models\Organisation;
use App\Models\OrganisationLockdown;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganisationLockdown>
 */
class OrganisationLockdownFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'locked_at' => now(),
            'is_drill' => false,
            'reactivated_at' => null,
        ];
    }

    public function drill(): static
    {
        return $this->state(['is_drill' => true]);
    }

    public function reactivated(): static
    {
        return $this->state([
            'reactivated_at' => now(),
            'reactivation_method' => 'owner_link',
        ]);
    }
}
