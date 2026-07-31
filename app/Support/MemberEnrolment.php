<?php

namespace App\Support;

use App\Enums\MemberStatus;
use Illuminate\Support\Carbon;

/**
 * The ONE source of the system-managed enrolment defaults (prompt 04/37). Both the
 * application-approval path (`ApproveApplication`) and the direct-create form
 * (`CreateMember`) fill these, so the carencia rule / active status / member-number
 * generation can never drift between the two ways a member is enrolled.
 */
class MemberEnrolment
{
    /**
     * @return array{member_no: string, status: string, joined_at: Carbon, carencia_ends_at: Carbon}
     */
    public static function defaults(string $organisationId): array
    {
        return [
            'member_no' => MemberNumber::next($organisationId),
            'status' => MemberStatus::ACTIVE->value,
            'joined_at' => now(),
            'carencia_ends_at' => now()->addDays((int) Settings::get('carencia_days', 15)),
        ];
    }
}
