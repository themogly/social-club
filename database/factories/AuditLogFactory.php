<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'actor_id' => null,
            'action' => fake()->randomElement(['created', 'updated', 'viewed', 'deleted']),
            'auditable_type' => null,
            'auditable_id' => null,
            'before' => [],
            'after' => [],
            'ip' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }
}
