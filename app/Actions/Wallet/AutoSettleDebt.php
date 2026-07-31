<?php

namespace App\Actions\Wallet;

use App\Models\Location;
use App\Models\Member;
use App\Support\Settings;
use App\Support\Wallet;

/**
 * Ring-fencing settlement: after a member gains credit at an UNFENCED location,
 * use it to auto-clear their debt at other UNFENCED locations (recorded via
 * TransferCredit). Ring-fenced locations (the per-location `ring_fenced` setting) are
 * excluded — they settle only by explicit manual transfer.
 */
class AutoSettleDebt
{
    /** @return int total cents moved to clear debt (0 if nothing settled). */
    public function handle(Member $member, Location $creditLocation): int
    {
        if (self::isRingFenced($creditLocation)) {
            return 0;
        }

        $available = Wallet::balance($member->id, $creditLocation->id);
        if ($available <= 0) {
            return 0;
        }

        $transfer = new TransferCredit;
        $settledTotal = 0;

        $others = Location::query()->withoutGlobalScopes()
            ->where('organisation_id', $member->organisation_id)
            ->whereKeyNot($creditLocation->id)
            ->get();

        foreach ($others as $location) {
            if ($available <= 0) {
                break;
            }
            if (self::isRingFenced($location)) {
                continue;
            }

            $debt = Wallet::balance($member->id, $location->id);
            if ($debt >= 0) {
                continue;
            }

            $settle = min($available, -$debt);
            $transfer->handle($member, $creditLocation, $location, $settle, 'Liquidación automática de deuda');
            $available -= $settle;
            $settledTotal += $settle;
        }

        return $settledTotal;
    }

    public static function isRingFenced(Location $location): bool
    {
        return (bool) Settings::get('ring_fenced', false, $location->id);
    }
}
