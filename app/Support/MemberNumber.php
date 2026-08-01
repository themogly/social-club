<?php

namespace App\Support;

use App\Models\Organisation;
use Illuminate\Support\Facades\DB;

/** Generates the next human-friendly member number for an org: unique, never reused. */
class MemberNumber
{
    public static function next(string $organisationId): string
    {
        // Allocate from a durable, monotonic per-org counter under a ROW LOCK (prompt 77): the old
        // COUNT(*) + 1 raced (concurrent enrolments collided on the unique index) AND reissued a number
        // after a purge/soft-delete. Locking the org row serialises allocation; the counter only increases,
        // so a number is never handed out twice — even after rows are deleted.
        return DB::transaction(function () use ($organisationId): string {
            $org = Organisation::withoutGlobalScopes()->whereKey($organisationId)->lockForUpdate()->firstOrFail();

            $sequence = (int) $org->member_no_sequence + 1;
            $org->update(['member_no_sequence' => $sequence]);

            return Settings::formatMemberNumber($sequence);
        });
    }

    /**
     * Raise the org's counter so it sits at or above $minSequence — never lower it. The member import
     * (prompt 131) accepts member numbers straight off the paper libro de socios; this is what stops the
     * NEXT enrolment's `next()` from re-issuing an imported number. Same row lock, same counter, so `next()`
     * stays the only allocator — this only fast-forwards it past what the import already placed.
     */
    public static function advanceAtLeast(string $organisationId, int $minSequence): void
    {
        DB::transaction(function () use ($organisationId, $minSequence): void {
            $org = Organisation::withoutGlobalScopes()->whereKey($organisationId)->lockForUpdate()->firstOrFail();

            if ((int) $org->member_no_sequence < $minSequence) {
                $org->update(['member_no_sequence' => $minSequence]);
            }
        });
    }

    /**
     * The integer sequence a member number encodes, or null when it carries no digits. Reads the trailing run
     * of digits so it copes with a prefix/padding ("M-00042" → 42) or a bare paper number ("42" → 42). Used to
     * fast-forward the counter past imported numbers; a value with no digits simply cannot collide with a
     * `formatMemberNumber()` output, so returning null (don't advance) is safe.
     */
    public static function parseSequence(string $memberNo): ?int
    {
        return preg_match('/(\d+)\s*$/', trim($memberNo), $m) === 1 ? (int) $m[1] : null;
    }
}
