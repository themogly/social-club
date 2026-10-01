<?php

namespace App\Actions\Memberships;

use App\Actions\RecordAuditLog;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\User;
use App\Support\MembershipCorrections;
use App\Support\MembershipExpiry;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Prompt 325 — *Corregir fechas*: the start and the expiry, with a reason. The status is recomputed by the nightly
 * sweep's own rule ({@see MembershipExpiry}), so a corrected membership reads exactly what the sweep would make it.
 * Dispensations already made under the old dates are not touched. Audited `membership.dates.corrected`.
 */
class CorrectMembershipDates
{
    /** @throws AuthorizationException|DomainException */
    public function handle(Membership $membership, CarbonInterface $startsAt, CarbonInterface $expiresAt, User $actor, string $reason): Membership
    {
        $membership->assertNotCovered(); // prompt 348 — a linked membership is re-dated through its home one
        MembershipCorrections::authorize($actor, $membership);
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException(__('Indica el motivo del cambio.'));
        }
        if ($membership->status === MembershipStatus::CANCELLED) {
            throw new DomainException(__('Esta membresía está anulada.'));
        }
        if ($expiresAt->lte($startsAt)) {
            throw new DomainException(__('La caducidad debe ser posterior al inicio.'));
        }

        $before = ['starts_at' => $membership->starts_at?->toDateString(), 'expires_at' => $membership->expires_at?->toDateString(), 'status' => $membership->status->value];
        $membership->update(['starts_at' => $startsAt, 'expires_at' => $expiresAt, 'status' => MembershipExpiry::statusOn($expiresAt, now())]);

        (new RecordAuditLog)->handle('membership.dates.corrected', $membership, $before, [
            'starts_at' => $startsAt->toDateString(), 'expires_at' => $expiresAt->toDateString(), 'status' => $membership->status->value, 'reason' => $reason,
        ]);

        return $membership;
    }

    /** Would a membership with these dates let its socio be served today? (Started, and not lapsed.) */
    public static function activeToday(CarbonInterface $startsAt, CarbonInterface $expiresAt): bool
    {
        return $startsAt->lte(now()) && MembershipExpiry::statusOn($expiresAt, now()) !== MembershipStatus::LAPSED;
    }
}
