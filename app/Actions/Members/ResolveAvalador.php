<?php

namespace App\Actions\Members;

use App\Models\Member;

/**
 * Resolve an "avalador" (sponsor) reference — a member number OR an unambiguous full name — to an active member
 * (prompt 244). ONE rule: `SubmitApplication` stores the resolved id, and the counter wizard shows what a typed
 * reference resolved to BEFORE the form is submitted (prompt 60).
 *
 * Prompt 273 (from `App\Support\AvaladorResolver`): the name match is narrowed in SQL by the first word before the
 * portable PHP comparison — it loaded every active member of the organisation on each debounced keystroke — and the
 * member comes back with only what a label needs (id, names, number), never the whole row (270: it reached the browser).
 */
class ResolveAvalador
{
    /** The columns a sponsor match carries — enough for `Member::avaladorLabel()`, nothing identifying beyond it. */
    private const COLUMNS = ['id', 'organisation_id', 'first_name', 'last_name', 'member_no'];

    /**
     * @return array{status: 'empty'|'found'|'none'|'multiple', member: ?Member}
     */
    public function handle(?string $organisationId, ?string $ref): array
    {
        $ref = trim((string) $ref);

        if ($organisationId === null || $ref === '') {
            return ['status' => 'empty', 'member' => null];
        }

        $pool = fn () => Member::query()->withoutGlobalScopes()->eligibleAvalador($organisationId);

        $byNumber = $pool()->where('member_no', $ref)->first(self::COLUMNS);
        if ($byNumber !== null) {
            return ['status' => 'found', 'member' => $byNumber];
        }

        // A NAME match, only when unambiguous. Narrowed in SQL by the first word, then compared in PHP so the full-name
        // comparison is portable across SQLite (dev) and MySQL (prod) — the comparison SubmitApplication always used.
        $needle = mb_strtolower($ref);
        $firstWord = strtok($ref, ' ') ?: $ref;
        $byName = $pool()->where('first_name', 'like', $firstWord.'%')->get(self::COLUMNS)
            ->filter(fn (Member $m): bool => mb_strtolower(trim($m->first_name.' '.$m->last_name)) === $needle)
            ->values();

        return match (true) {
            $byName->count() === 1 => ['status' => 'found', 'member' => $byName->first()],
            $byName->count() > 1 => ['status' => 'multiple', 'member' => null],
            default => ['status' => 'none', 'member' => null],
        };
    }
}
