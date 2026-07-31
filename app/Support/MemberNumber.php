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
}
