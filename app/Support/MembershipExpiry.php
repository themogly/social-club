<?php

namespace App\Support;

use App\Enums\MembershipStatus;
use Carbon\CarbonInterface;

/**
 * Prompt 325 — the ONE rule for what a membership's expiry makes its status: past → LAPSED; inside the renewal window
 * (`expiring_soon_days`) → EXPIRING_SOON; otherwise (or no expiry) → ACTIVE. The nightly sweep
 * (`SweepMembershipExpiry`) draws its lines from here, and *Corregir fechas* recomputes a corrected membership with
 * it — so a corrected membership reads exactly what the sweep would make it.
 */
final class MembershipExpiry
{
    /** The renewal window, in days. */
    public static function windowDays(): int
    {
        return (int) Settings::get('expiring_soon_days', 30);
    }

    /** The last expiry that still falls inside the window, seen from `$now`. */
    public static function windowEnd(CarbonInterface $now): CarbonInterface
    {
        return $now->copy()->addDays(self::windowDays());
    }

    public static function statusOn(?CarbonInterface $expiresAt, CarbonInterface $now): MembershipStatus
    {
        return match (true) {
            $expiresAt === null => MembershipStatus::ACTIVE,
            $expiresAt->lt($now) => MembershipStatus::LAPSED,
            $expiresAt->lte(self::windowEnd($now)) => MembershipStatus::EXPIRING_SOON,
            default => MembershipStatus::ACTIVE,
        };
    }
}
