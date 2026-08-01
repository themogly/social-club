<?php

namespace App\Actions\Members;

use App\Actions\RecordAuditLog;
use App\Models\Member;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Set (or clear) a member's per-member gram-limit override (prompt 81 — wiring the previously-dead
 * `member.limits.set` permission). These caps sit at the TOP of the precedence chain
 * (member → tier → location → org, see ResolveMemberLimits), so an override here beats the tier and the
 * org default. Weight is integer CENTIGRAMS; `null` CLEARS the override, dropping the member back to their
 * tier/location/org limit. Gated through MemberPolicy::setLimits, reasoned and audited. It only moves the
 * numeric cap — every other compliance gate (age, carencia, avalador, active fee) is untouched, and the
 * counter still enforces the resulting limit live.
 */
class SetMemberLimits
{
    public function handle(Member $member, User $actor, ?int $dailyLimitCg, ?int $monthlyLimitCg, string $reason): Member
    {
        if ($actor->cannot('setLimits', $member)) {
            throw new AuthorizationException('Setting a per-member limit requires the member.limits.set permission.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('A limit override requires a reason.');
        }

        foreach ([$dailyLimitCg, $monthlyLimitCg] as $cg) {
            if ($cg !== null && $cg < 0) {
                throw new RuntimeException('A limit override cannot be negative.');
            }
        }

        return DB::transaction(function () use ($member, $dailyLimitCg, $monthlyLimitCg, $reason): Member {
            // No health/special-category fields in the audit diff — only the two numeric caps and the reason.
            $before = ['daily_limit_cg' => $member->daily_limit_cg, 'monthly_limit_cg' => $member->monthly_limit_cg];

            $member->forceFill([
                'daily_limit_cg' => $dailyLimitCg,
                'monthly_limit_cg' => $monthlyLimitCg,
            ])->save();

            (new RecordAuditLog)->handle('member.limits.set', $member, $before, [
                'daily_limit_cg' => $dailyLimitCg,
                'monthly_limit_cg' => $monthlyLimitCg,
                'reason' => $reason,
            ]);

            return $member;
        });
    }
}
