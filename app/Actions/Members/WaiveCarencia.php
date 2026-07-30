<?php

namespace App\Actions\Members;

use App\Actions\RecordAuditLog;
use App\Models\Member;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Waive a member's carencia (waiting period) — manager-only (`carencia.waive`),
 * always logged. Sets carencia_ends_at to now so the member becomes dispensable.
 */
class WaiveCarencia
{
    public function handle(Member $member, User $actor, ?string $reason = null): Member
    {
        if (! $actor->can('carencia.waive')) {
            throw new AuthorizationException('Waiving carencia requires the carencia.waive permission.');
        }

        $before = ['carencia_ends_at' => $member->carencia_ends_at?->toIso8601String()];

        $member->update(['carencia_ends_at' => now()]);

        (new RecordAuditLog)->handle('member.carencia.waived', $member, $before, [
            'carencia_ends_at' => $member->carencia_ends_at?->toIso8601String(),
            'reason' => $reason,
        ]);

        return $member;
    }
}
