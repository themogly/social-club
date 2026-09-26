<?php

namespace App\Actions\Members;

use App\Actions\RecordAuditLog;
use App\Models\Member;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Approve (or withdraw) a member's tab — the ONLY writer of `members.debt_limit_cents` (prompt 259), which is
 * deliberately not mass-assignable, so no form, import or sign-up path can set it by accident.
 *
 * "Approved up to €X": one figure per member, checked by the wallet writer against the member's TOTAL debt
 * across every sede. Null or 0 = no tab. Gated by `MemberPolicy::approveDebt` (owner always; a manager only
 * where the sede's owner-set `managers_can_approve_debt` is on), reasoned and audited.
 *
 * LOWERING a limit below what the member already owes is allowed and claws nothing back: they are simply over
 * their new limit, so no further debt can be added until they pay down under it.
 */
class SetMemberDebtLimit
{
    public function handle(Member $member, User $actor, ?int $limitCents, string $reason): Member
    {
        if ($actor->cannot('approveDebt', $member)) {
            throw new AuthorizationException('Approving a member tab requires the owner, or a manager where the sede allows it.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('A tab approval requires a reason.');
        }

        if ($limitCents !== null && $limitCents < 0) {
            throw new RuntimeException('A tab limit cannot be negative.');
        }

        $limitCents = $limitCents === 0 ? null : $limitCents;

        return DB::transaction(function () use ($member, $limitCents, $reason): Member {
            $before = ['debt_limit_cents' => $member->debt_limit_cents];

            $member->forceFill(['debt_limit_cents' => $limitCents])->save();

            (new RecordAuditLog)->handle('member.debt_limit.set', $member, $before, [
                'debt_limit_cents' => $limitCents,
                'reason' => $reason,
            ]);

            return $member;
        });
    }
}
