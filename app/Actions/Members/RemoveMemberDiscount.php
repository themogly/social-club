<?php

namespace App\Actions\Members;

use App\Actions\RecordAuditLog;
use App\Models\MemberDiscount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Remove a per-member discount. Owner-only (`member.discount.assign`), audited (the
 * removed value + the captured reason). Never a hard business-logic change beyond
 * deleting the assignment the resolver reads.
 */
class RemoveMemberDiscount
{
    public function handle(MemberDiscount $memberDiscount, User $actor, ?string $reason = null): void
    {
        if (! $actor->can('member.discount.assign')) {
            throw new AuthorizationException('Removing a member discount requires the member.discount.assign permission.');
        }

        $member = $memberDiscount->member;
        $before = [
            'mode' => $memberDiscount->mode?->value,
            'value_bp' => $memberDiscount->value_bp,
            'value_cents' => $memberDiscount->value_cents,
            'expires_at' => $memberDiscount->expires_at?->toDateString(),
            'reason' => $reason,
        ];

        $memberDiscount->delete();

        (new RecordAuditLog)->handle('member.discount.removed', $member, $before, null);
    }
}
