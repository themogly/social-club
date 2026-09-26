<?php

namespace App\Support;

use App\Enums\MemberStatus;
use App\Models\Member;

/**
 * Resolve an "avalador" (sponsor) reference — a member number OR an unambiguous full name — to an active
 * member (prompt 244). ONE writer for the rule: `SubmitApplication` stores the resolved id, and the counter
 * wizard shows what a typed reference resolved to BEFORE the form is submitted (prompt 60 — a lookup's outcome
 * is visible in advance). A name that matches nobody, or two people, was previously accepted as free text with
 * nobody told; now the operator sees it.
 */
class AvaladorResolver
{
    /**
     * @return array{status: 'empty'|'found'|'none'|'multiple', member: ?Member}
     */
    public static function resolve(?string $organisationId, ?string $ref): array
    {
        $ref = trim((string) $ref);

        if ($organisationId === null || $ref === '') {
            return ['status' => 'empty', 'member' => null];
        }

        $base = fn () => Member::query()->withoutGlobalScopes()
            ->where('organisation_id', $organisationId)
            ->where('status', MemberStatus::ACTIVE->value);

        $byNumber = $base()->where('member_no', $ref)->first();
        if ($byNumber !== null) {
            return ['status' => 'found', 'member' => $byNumber];
        }

        // A NAME match, only when unambiguous. Compared in PHP so the full-name comparison is portable across
        // SQLite (dev) and MySQL (prod) — the same comparison SubmitApplication always used.
        $needle = mb_strtolower($ref);
        $byName = $base()->get(['id', 'first_name', 'last_name', 'member_no'])
            ->filter(fn (Member $m): bool => mb_strtolower(trim($m->first_name.' '.$m->last_name)) === $needle)
            ->values();

        return match (true) {
            $byName->count() === 1 => ['status' => 'found', 'member' => $byName->first()],
            $byName->count() > 1 => ['status' => 'multiple', 'member' => null],
            default => ['status' => 'none', 'member' => null],
        };
    }
}
