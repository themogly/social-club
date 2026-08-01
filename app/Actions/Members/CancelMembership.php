<?php

namespace App\Actions\Members;

use App\Actions\RecordAuditLog;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Process a member's voluntary departure (baja) — the exit path that never existed (prompt 80). Members could
 * be expelled (a punitive sanction) but could not simply leave. This cancels every ACTIVE membership
 * (MembershipStatus::CANCELLED — a status previously assigned NOWHERE), then records the baja through the one
 * member-status writer (INACTIVE + left_at), so the libro de socios shows a departure date instead of "—".
 * Reasoned, audited, and refused once the member has already left. It gates on members.edit (a routine admin
 * task), NOT member.sanction — a baja is voluntary, not a punishment.
 */
class CancelMembership
{
    public function handle(Member $member, User $actor, string $reason): Member
    {
        if ($actor->cannot('update', $member)) {
            throw new AuthorizationException('Recording a member baja requires the members.edit permission.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('A baja requires a reason.');
        }

        if ($member->left_at !== null || in_array($member->status, [MemberStatus::INACTIVE, MemberStatus::EXPIRED, MemberStatus::EXPELLED], true)) {
            throw new RuntimeException('This member has already left the association.');
        }

        return DB::transaction(function () use ($member, $actor, $reason): Member {
            // Cancel every active membership across all sedes (a baja leaves the association, not one premises).
            $cancelled = 0;
            foreach ($member->memberships()->withoutGlobalScopes()->where('status', MembershipStatus::ACTIVE->value)->get() as $membership) {
                $membership->update(['status' => MembershipStatus::CANCELLED]);
                $cancelled++;
            }

            (new RecordAuditLog)->handle('member.membership.cancelled', $member, null, [
                'memberships_cancelled' => $cancelled,
                'reason' => $reason,
            ]);

            // The baja itself — the single member-status writer sets left_at + status and its own audit row.
            return (new TransitionMemberStatus)->handle($member, MemberStatus::INACTIVE, $reason, $actor->id);
        });
    }
}
