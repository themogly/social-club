<?php

namespace App\Actions\Members;

use App\Actions\RecordAuditLog;
use App\Models\Member;
use App\Models\MemberDiscount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Assign a discount to a member — a linked standard Discount or an inline custom
 * value, with an optional expiry. Owner-only (`member.discount.assign`), audited.
 *
 * @phpstan-type AssignData array{discount_id?: ?string, mode?: mixed, value_bp?: ?int, value_cents?: ?int, expires_at?: mixed}
 */
class AssignMemberDiscount
{
    /**
     * @param  AssignData  $data
     */
    public function handle(Member $member, User $actor, array $data): MemberDiscount
    {
        if (! $actor->can('member.discount.assign')) {
            throw new AuthorizationException('Assigning a member discount requires the member.discount.assign permission.');
        }

        $memberDiscount = MemberDiscount::create([
            'member_id' => $member->id,
            'discount_id' => $data['discount_id'] ?? null,
            'mode' => $data['mode'] ?? null,
            'value_bp' => $data['value_bp'] ?? null,
            'value_cents' => $data['value_cents'] ?? null,
            'assigned_by' => $actor->id,
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        (new RecordAuditLog)->handle('member.discount.assigned', $member, null, [
            'discount_id' => $data['discount_id'] ?? null,
            'mode' => isset($data['mode']) ? (string) ($data['mode']->value ?? $data['mode']) : null,
            'assigned_by' => $actor->id,
        ]);

        return $memberDiscount;
    }
}
