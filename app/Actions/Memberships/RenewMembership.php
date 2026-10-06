<?php

namespace App\Actions\Memberships;

use App\Actions\RecordAuditLog;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\User;
use App\Support\ManagerApproval;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Renew a membership: extend from the LATER of today and the current expiry (so an
 * early renewal doesn't lose remaining time, and a lapsed one restarts from today),
 * reactivate, and re-apply the tier period. Fee override rules mirror enrolment.
 *
 * @phpstan-type RenewOptions array{fee_cents?: int, actor?: ?User, fee_override_reason?: ?string}
 */
class RenewMembership
{
    /**
     * @param  RenewOptions  $options
     */
    public function handle(Membership $membership, array $options = []): Membership
    {
        $membership->assertNotCovered(); // prompt 348 — renewed through its home membership
        $tier = $membership->tier;
        $base = CarbonImmutable::now();
        if ($membership->expires_at !== null && $membership->expires_at->greaterThan($base)) {
            $base = CarbonImmutable::parse($membership->expires_at);
        }

        $defaultFee = $tier->default_fee_cents->cents;
        $feeCents = $options['fee_cents'] ?? $membership->fee_cents->cents;
        $overridden = $feeCents !== $defaultFee;
        $actor = $options['actor'] ?? null;

        if ($overridden && ! ($actor?->can('membership.fee.override') ?? false)) {
            throw new AuthorizationException('Overriding the membership fee requires the membership.fee.override permission.');
        }

        $membership->update([
            'expires_at' => EnrolMembership::expiryFor($tier->default_period, $base, $membership->expires_at),
            'status' => MembershipStatus::ACTIVE,
            'fee_cents' => $feeCents,
            'fee_override_by' => $overridden ? $actor->id : $membership->fee_override_by,
            'reminder_sent_for' => null,
        ]);

        // Prompt 356 — an optional reason is not asked for: a holder of `reasons.optional` who overrides the fee and gives
        // none has «Aprobado por responsable» recorded, as for a price adjustment or a waiver.
        $reason = trim((string) ($options['fee_override_reason'] ?? ''));
        if ($overridden && $reason === '' && ManagerApproval::allows($actor)) {
            $reason = ManagerApproval::reason();
        }
        $reason = $reason !== '' ? $reason : null;

        (new RecordAuditLog)->handle('membership.renewed', $membership, null, [
            'expires_at' => $membership->expires_at?->toDateString(),
            'reason' => $overridden ? $reason : null,
            // Prompt 356 — the fee changed with no reason given, by a holder of `reasons.optional`: say so.
            ...($overridden && ManagerApproval::applies($actor, $reason) ? [ManagerApproval::AUDIT_KEY => ManagerApproval::PERMISSION] : []),
        ]);

        return $membership;
    }
}
