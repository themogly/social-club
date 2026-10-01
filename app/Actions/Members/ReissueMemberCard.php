<?php

namespace App\Actions\Members;

use App\Actions\RecordAuditLog;
use App\Models\Member;
use App\Models\User;

/**
 * Prompt 348 — *Reemitir carné*: a card that was shared or lost stops working. The QR token is rotated (the old one is
 * revoked by `IssueMemberToken`, so scanning it no longer finds anybody); when the member has an e-mail address the new
 * card is sent through the ONE card path (`SendMemberCard`, which itself rotates — so it is the only rotation then).
 * Audited, naming who did it. Returns whether the new card was e-mailed.
 */
class ReissueMemberCard
{
    public function handle(Member $member, ?User $actor, ?string $operatorId = null): bool
    {
        $emailed = filled($member->email) ? (new SendMemberCard)->handle($member) : false;
        if (! $emailed) {
            (new IssueMemberToken)->handle($member);
        }

        (new RecordAuditLog)->handle('member.card_reissued', $member, null, array_filter([
            'emailed' => $emailed,
            'operator_id' => $operatorId,
        ], fn ($v) => $v !== null));

        return $emailed;
    }
}
