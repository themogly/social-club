<?php

namespace App\Actions\Members;

use App\Actions\RecordAuditLog;
use App\Models\MemberDiscount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Update an existing per-member discount (value / expiry). Owner-only
 * (`member.discount.assign`), audited from → to. Mirrors {@see AssignMemberDiscount};
 * the resolver reads the same MemberDiscount, so no second pricing path is introduced.
 *
 * @phpstan-type UpdateData array{mode?: mixed, value_bp?: ?int, value_cents?: ?int, expires_at?: mixed, reason?: ?string}
 */
class UpdateMemberDiscount
{
    /**
     * @param  UpdateData  $data
     */
    public function handle(MemberDiscount $memberDiscount, User $actor, array $data): MemberDiscount
    {
        if (! $actor->can('member.discount.assign')) {
            throw new AuthorizationException('Editing a member discount requires the member.discount.assign permission.');
        }

        $before = $this->snapshot($memberDiscount);

        $memberDiscount->update([
            'mode' => $data['mode'] ?? null,
            'value_bp' => $data['value_bp'] ?? null,
            'value_cents' => $data['value_cents'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'assigned_by' => $actor->id,
        ]);
        $memberDiscount->refresh();

        (new RecordAuditLog)->handle(
            'member.discount.updated',
            $memberDiscount->member,
            $before,
            $this->snapshot($memberDiscount) + ['reason' => $data['reason'] ?? null],
        );

        return $memberDiscount;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(MemberDiscount $discount): array
    {
        return [
            'mode' => $discount->mode?->value,
            'value_bp' => $discount->value_bp,
            'value_cents' => $discount->value_cents,
            'expires_at' => $discount->expires_at?->toDateString(),
        ];
    }
}
