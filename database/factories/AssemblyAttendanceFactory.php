<?php

namespace Database\Factories;

use App\Enums\AttendanceMode;
use App\Models\AssemblyAttendance;
use App\Models\Convocatoria;
use App\Models\Member;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssemblyAttendance>
 */
class AssemblyAttendanceFactory extends Factory
{
    protected $model = AssemblyAttendance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'convocatoria_id' => Convocatoria::factory(),
            'member_id' => Member::factory(),
            'name' => fake()->name(),
            'mode' => AttendanceMode::PRESENT,
            'proxy_holder' => null,
            'recorded_by' => null,
        ];
    }

    public function proxy(string $holder = 'Junta directiva'): static
    {
        return $this->state(fn (): array => ['mode' => AttendanceMode::PROXY, 'proxy_holder' => $holder]);
    }
}
