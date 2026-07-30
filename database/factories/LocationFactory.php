<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'name' => fake()->company(),
            'address' => fake()->address(),
            'capacity' => 50,
            'timezone' => 'Europe/Madrid',
            'business_day_cutoff' => '06:00',
            'opening_time' => '09:00',
            'closing_time' => '22:00',
            'accent' => fake()->hexColor(),
            'settings' => [],
            'active' => true,
        ];
    }
}
