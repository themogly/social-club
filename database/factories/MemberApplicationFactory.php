<?php

namespace Database\Factories;

use App\Enums\ApplicationStatus;
use App\Models\MemberApplication;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemberApplication>
 */
class MemberApplicationFactory extends Factory
{
    protected $model = MemberApplication::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'location_id' => null,
            'invite_token_hash' => hash('sha256', fake()->uuid()),
            'payload' => [],
            'status' => ApplicationStatus::PENDING,
            'reject_reason' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'resulting_member_id' => null,
        ];
    }

    /**
     * A SUBMITTED application — the applicant has sent the form, so submitted_at is stamped (as
     * ApplicationController does). This is the point at which an invitation becomes a reviewable
     * application, i.e. when Approve/Reject/Waiting-list are offered (prompt 152).
     */
    public function submitted(): static
    {
        return $this->state(fn (): array => ['submitted_at' => now()]);
    }
}
