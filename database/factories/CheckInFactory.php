<?php

namespace Database\Factories;

use App\Enums\CheckInMethod;
use App\Models\CheckIn;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CheckIn>
 */
class CheckInFactory extends Factory
{
    protected $model = CheckIn::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'member_id' => Member::factory(),
            'location_id' => Location::factory(),
            'checked_in_at' => now(),
            'checked_out_at' => null,
            'auto_checked_out' => false,
            'operator_id' => null,
            'method' => CheckInMethod::QR,
        ];
    }
}
