<?php

namespace App\Actions\Members;

use App\Actions\RecordAuditLog;
use App\Enums\MemberKind;
use App\Models\Member;
use App\Support\Settings;

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

    /**
     * Make a standard member temporary (prompt 165) — the direction that had no path at all, for the
     * flag set in error at the counter, or the member who asks to be treated as a short-stay visitor.
     *
     * **The window starts NOW, never from their join date.** Counting a `temporary_window_days` window
     * from an old join date would expire a long-standing member the instant they were converted, and the
     * sweep anonymises on expiry — so a retroactive window is not a cosmetic bug, it is data loss at a
     * counter. `temporary_reminder_sent_at` is cleared with it: it is the sweep's one-reminder-per-window
     * marker, and a stale one from an earlier stint would silently swallow the new window's warning.
     *
     * Carries a REASON, unlike converting the other way: this direction schedules a person's automatic
     * anonymisation, which is at least as significant as a status change (see TransitionMemberStatus).
     */
    public function convertToTemporary(Member $member, ?string $reason = null, ?int $days = null): Member
    {
        $days ??= (int) Settings::get('temporary_window_days', 30);
        $before = $this->snapshot($member);

        $member->update([
            'kind' => MemberKind::TEMPORARY->value,
            'temporary_expires_at' => now()->addDays(max(1, $days)),
            'temporary_reminder_sent_at' => null,
        ]);

        (new RecordAuditLog)->handle(
            'member.temporary.applied',
            $member,
            $before,
            $this->snapshot($member->refresh()) + ['reason' => $reason],
        );

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
