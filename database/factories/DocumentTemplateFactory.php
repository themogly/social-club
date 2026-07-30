<?php

namespace Database\Factories;

use App\Models\DocumentTemplate;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentTemplate>
 */
class DocumentTemplateFactory extends Factory
{
    protected $model = DocumentTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'type' => fake()->randomElement(['CONSENT', 'DECLARATION', 'REGISTRATION_FORM']),
            'body' => fake()->paragraphs(3, true),
            'version' => 1,
            'active' => true,
        ];
    }
}
