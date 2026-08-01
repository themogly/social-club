<?php

namespace App\Actions\Members;

use App\Actions\RecordAuditLog;
use App\Models\MemberDiscount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Update an existing per-member discount — its EXPIRY only (prompt 119). Owner-only
 * (`member.discount.assign`), audited from → to. The discount's VALUE is not editable by hand: a linked
 * template owns its value, and a legacy inline value is frozen (to change a rate, remove and reassign a named
 * discount). So the link (`discount_id`) and any inline value are deliberately left untouched here.
 *
 * @phpstan-type UpdateData array{expires_at?: mixed, reason?: ?string}
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

        // Only the expiry moves — never the value or the template link (prompt 119).
        $memberDiscount->update([
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
