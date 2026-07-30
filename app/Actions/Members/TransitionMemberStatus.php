<?php

namespace App\Actions\Members;

use App\Actions\RecordAuditLog;
use App\Enums\MemberStatus;
use App\Enums\SanctionType;
use App\Models\Member;
use Illuminate\Support\Facades\Auth;

/**
 * Move a member through the lifecycle (APPLICANT → ACTIVE → INACTIVE|EXPIRED|
 * SUSPENDED|EXPELLED). Every transition carries a reason, is attributed and
 * audited; SUSPENDED/EXPELLED also write a MemberSanction (feeds the acta in prompt 16).
 */
class TransitionMemberStatus
{
    public function handle(Member $member, MemberStatus $to, ?string $reason = null, ?string $actorId = null): Member
    {
        $from = $member->status;
        $actorId ??= Auth::id();

        $member->status = $to;
        if (in_array($to, [MemberStatus::INACTIVE, MemberStatus::EXPIRED, MemberStatus::EXPELLED], true) && $member->left_at === null) {
            $member->left_at = now();
        }
        $member->save();

        if (in_array($to, [MemberStatus::SUSPENDED, MemberStatus::EXPELLED], true)) {
            $member->sanctions()->create([
                'type' => $to === MemberStatus::EXPELLED ? SanctionType::EXPULSION : SanctionType::SUSPENSION,
                'reason' => $reason,
                'from_date' => now(),
                'recorded_by' => $actorId,
            ]);
        }

        (new RecordAuditLog)->handle(
            'member.status.'.strtolower($to->value),
            $member,
            ['status' => $from->value],
            ['status' => $to->value, 'reason' => $reason],
        );

        return $member;
    }
}
