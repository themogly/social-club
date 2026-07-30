<?php

namespace Database\Factories;

use App\Enums\MemberDocumentType;
use App\Models\Member;
use App\Models\MemberDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemberDocument>
 */
class MemberDocumentFactory extends Factory
{
    protected $model = MemberDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'type' => MemberDocumentType::ID,
            'path' => 'documents/'.fake()->uuid().'.pdf',
            'uploaded_by' => null,
            'signed_at' => null,
            'version' => 1,
            'generated_from' => null,
        ];
    }
}
