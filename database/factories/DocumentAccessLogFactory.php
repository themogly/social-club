<?php

namespace Database\Factories;

use App\Models\DocumentAccessLog;
use App\Models\MemberDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentAccessLog>
 */
class DocumentAccessLogFactory extends Factory
{
    protected $model = DocumentAccessLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_id' => null,
            'member_document_id' => MemberDocument::factory(),
            'viewed_at' => now(),
            'ip' => fake()->ipv4(),
        ];
    }
}
