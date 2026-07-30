<?php

namespace Database\Factories;

use App\Enums\DataRequestType;
use App\Models\DataRequest;
use App\Models\Member;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataRequest>
 */
class DataRequestFactory extends Factory
{
    protected $model = DataRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'member_id' => Member::factory(),
            'type' => DataRequestType::ACCESS,
            'requested_at' => now(),
            'completed_at' => null,
            'handled_by' => null,
        ];
    }
}
