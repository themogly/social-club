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

        // On-site gram-equivalent aggregates BOTH kinds: a WEIGHT batch's remaining_cg and
        // a UNIT batch's remaining_units × the genetic's grams_per_unit_cg — one compliance figure.
        $onSiteCg = (int) Batch::query()->withoutGlobalScopes()
            ->join('genetics', 'batches.genetic_id', '=', 'genetics.id')
            ->where('batches.location_id', $location->id)
            ->where('batches.status', BatchStatus::OPEN->value)
            ->selectRaw("COALESCE(SUM(CASE WHEN genetics.unit_type = 'UNIT' THEN batches.remaining_units * genetics.grams_per_unit_cg ELSE batches.remaining_cg END), 0) as cg")
            ->value('cg');

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
