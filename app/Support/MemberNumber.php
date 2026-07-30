<?php

namespace App\Support;

use App\Models\Member;

/** Generates the next human-friendly member number for an org: unique, never reused. */
class MemberNumber
{
    public static function next(string $organisationId): string
    {
        // Count includes soft-deleted so numbers are never reused.
        $sequence = Member::withoutGlobalScopes()->withTrashed()
            ->where('organisation_id', $organisationId)->count() + 1;

        do {
            $candidate = Settings::formatMemberNumber($sequence);
            $sequence++;
        } while (Member::withoutGlobalScopes()
            ->where('organisation_id', $organisationId)
            ->where('member_no', $candidate)
            ->exists());

        return $candidate;
    }
}
