<?php

namespace App\Actions\Members;

use App\Actions\RecordAuditLog;
use App\Enums\MemberKind;
use App\Models\Member;

/**
 * Convert a temporary member to standard, or extend their window, before the removal
 * sweep reaches them (prompt 31) — for the visitor who decides to stay. Reuses the
 * existing person record (no re-enrolment); both operations are audited.
 */
class ManageTemporaryMember
{
    /** Make a temporary member permanent — clears the auto-expiry. */
    public function convertToStandard(Member $member): Member
    {
        $before = $this->snapshot($member);

        $member->update([
            'kind' => MemberKind::STANDARD->value,
            'temporary_expires_at' => null,
        ]);

        (new RecordAuditLog)->handle('member.temporary.converted', $member, $before, $this->snapshot($member->refresh()));

        return $member;
    }

    /** Push the temporary window out by N days (from the later of its current expiry or now). */
    public function extend(Member $member, int $days): Member
    {
        $base = $member->temporary_expires_at !== null && $member->temporary_expires_at->isFuture()
            ? $member->temporary_expires_at
            : now();

        $before = $this->snapshot($member);

        $member->update(['temporary_expires_at' => $base->copy()->addDays($days)]);

        (new RecordAuditLog)->handle('member.temporary.extended', $member, $before, $this->snapshot($member->refresh()));

        return $member;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Member $member): array
    {
        return [
            'kind' => $member->kind->value,
            'temporary_expires_at' => $member->temporary_expires_at?->toDateString(),
        ];
    }
}
