<?php

namespace App\Support;

use App\Enums\MemberStatus;
use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

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

        $base = fn () => self::pool($organisationId);

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

    /**
     * WHO may be an avalador at all — the ONE definition the counter's resolver and the admin form's picker share
     * (prompt 264): active members of this organisation.
     *
     * @return Builder<Member>
     */
    public static function pool(string $organisationId): Builder
    {
        return Member::query()->withoutGlobalScopes()
            ->where('organisation_id', $organisationId)
            ->where('status', MemberStatus::ACTIVE->value);
    }

    /**
     * The admin member form's search (prompt 264): first name, last name OR member number, in one box — a sponsor is
     * known by name, not by number. It used to search `member_no` only, so typing the sponsor's surname found nobody.
     *
     * @return Collection<int, Member>
     */
    public static function candidates(string $organisationId, string $term, ?string $excludeMemberId = null, int $limit = 20): Collection
    {
        $term = trim($term);

        if ($term === '') {
            return new Collection;
        }

        return self::pool($organisationId)
            ->when($excludeMemberId !== null, fn (Builder $q) => $q->whereKeyNot($excludeMemberId)) // nobody sponsors themself
            ->where(fn (Builder $q) => $q
                ->where('first_name', 'like', '%'.$term.'%')
                ->orWhere('last_name', 'like', '%'.$term.'%')
                ->orWhere('member_no', 'like', '%'.$term.'%'))
            ->orderBy('last_name')->orderBy('first_name')
            ->limit($limit)
            ->get(['id', 'first_name', 'last_name', 'member_no']);
    }

    /** "Nombre Apellidos · M-00028" — the person, and the number that tells two namesakes apart. Never DNI or birth date. */
    public static function label(Member $member): string
    {
        return trim($member->first_name.' '.$member->last_name).' · '.$member->member_no;
    }
}
