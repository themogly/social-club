<?php

namespace App\Support;

use App\Enums\BatchStatus;
use App\Enums\MemberStatus;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Member;

/**
 * Premises stock ceiling — a COMPLIANCE signal (not merchandising). Warns when the
 * on-site cannabis weight exceeds `active_members × daily_limit × ceiling_days`.
 * Returns the arithmetic (not a bare number), because the figure is a setting and
 * different sources quote different day counts (NOTES §A).
 *
 * @return array{on_site_cg: int, ceiling_cg: int, active_members: int, daily_limit_cg: int, ceiling_days: int, exceeded: bool}
 */
class StockCeiling
{
    /**
     * @return array{on_site_cg: int, ceiling_cg: int, active_members: int, daily_limit_cg: int, ceiling_days: int, exceeded: bool}
     */
    public static function forLocation(Location $location): array
    {
        $activeMembers = (int) Member::query()->withoutGlobalScopes()
            ->where('organisation_id', $location->organisation_id)
            ->where('status', MemberStatus::ACTIVE->value)
            ->count();

        $dailyLimitCg = (int) app(ActiveScope::class)->forLocation(
            $location->id,
            fn () => Settings::get('daily_limit_cg'),
        );
        $ceilingDays = (int) Settings::get('stock_ceiling_days', 5);
        $ceilingCg = $activeMembers * $dailyLimitCg * $ceilingDays;

        $onSiteCg = (int) Batch::query()->withoutGlobalScopes()
            ->where('location_id', $location->id)
            ->where('status', BatchStatus::OPEN->value)
            ->sum('remaining_cg');

        return [
            'on_site_cg' => $onSiteCg,
            'ceiling_cg' => $ceilingCg,
            'active_members' => $activeMembers,
            'daily_limit_cg' => $dailyLimitCg,
            'ceiling_days' => $ceilingDays,
            'exceeded' => $onSiteCg > $ceilingCg,
        ];
    }
}
