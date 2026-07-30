<?php

namespace Database\Factories;

use App\Enums\IdDocumentType;
use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    protected $model = Member::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'member_no' => fake()->unique()->numerify('M-#####'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'date_of_birth' => fake()->dateTimeBetween('-60 years', '-19 years'),
            'address' => fake()->address(),
            'photo_path' => null,
            'document_type' => IdDocumentType::DNI,
            'document_number' => fake()->bothify('########?'),
            'document_scan_path' => null,
            'status' => MemberStatus::ACTIVE,
            'is_therapeutic' => false,
            'avalador_member_id' => null,
            'joined_at' => now(),
            'left_at' => null,
            'carencia_ends_at' => null,
            'declared_monthly_cg' => fake()->numberBetween(1000, 30000),
            'daily_limit_cg' => null,
            'monthly_limit_cg' => null,
            'sole_association_declared_at' => null,
            'anonymised_at' => null,
        ];
    }
}
