<?php

namespace Database\Factories;

use App\Enums\SettingType;
use App\Models\Organisation;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    protected $model = Setting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'location_id' => null,
            'key' => fake()->slug(2),
            'value' => fake()->word(),
            'type' => SettingType::STRING,
        ];
    }
}
