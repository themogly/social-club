<?php

namespace App\Actions\Memberships;

use App\Actions\RecordAuditLog;
use App\Enums\MembershipPeriod;
use App\Enums\MembershipStatus;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Enrol a member at a location on a tier. Expiry is computed from the tier period;
 * the fee defaults from the tier but may be overridden — an override requires the
 * `membership.fee.override` permission and records who overrode (and why, in the
 * audit trail).
 *
 * @phpstan-type EnrolOptions array{starts_at?: CarbonInterface, expires_at?: ?CarbonInterface, fee_cents?: int, actor?: ?User, fee_override_reason?: ?string, status?: MembershipStatus}
 */
class EnrolMembership
{
    /**
     * @param  EnrolOptions  $options
     */
    public function handle(Member $member, Location $location, MembershipTier $tier, array $options = []): Membership
    {
        $startsAt = $options['starts_at'] ?? now();
        $defaultFee = $tier->default_fee_cents->cents;
        $feeCents = $options['fee_cents'] ?? $defaultFee;
        $overridden = $feeCents !== $defaultFee;
        $actor = $options['actor'] ?? null;

        if ($overridden && ! ($actor?->can('membership.fee.override') ?? false)) {
            throw new AuthorizationException('Overriding the membership fee requires the membership.fee.override permission.');
        }

        // Status defaults to ACTIVE (the only enrolment path until the paper-register import, prompt 131, which
        // brings across memberships that are already lapsed/cancelled — those must NOT count toward the sede's
        // stock ceiling, so an imported non-active membership stays non-active here).
        $membership = Membership::create([
            'organisation_id' => $member->organisation_id,
            'member_id' => $member->id,
            'location_id' => $location->id,
            'tier_id' => $tier->id,
            'starts_at' => $startsAt,
            'expires_at' => self::expiryFor($tier->default_period, $startsAt, $options['expires_at'] ?? null),
            'fee_cents' => $feeCents,
            'fee_override_by' => $overridden ? $actor->id : null,
            'status' => $options['status'] ?? MembershipStatus::ACTIVE,
        ]);

        (new RecordAuditLog)->handle('membership.enrolled', $membership, null, [
            'tier' => $tier->name,
            'fee_cents' => $feeCents,
            'fee_overridden' => $overridden,
            'reason' => $overridden ? ($options['fee_override_reason'] ?? null) : null,
        ]);

        return $membership;
    }

    public static function expiryFor(MembershipPeriod $period, CarbonInterface $from, ?CarbonInterface $custom): ?CarbonInterface
    {
        return match ($period) {
            MembershipPeriod::MONTHLY => $from->copy()->addMonth(),
            MembershipPeriod::YEARLY => $from->copy()->addYear(),
            MembershipPeriod::LIFETIME => null,
            MembershipPeriod::CUSTOM => $custom,
        };
    }
}
