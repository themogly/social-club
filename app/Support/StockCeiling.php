<?php

namespace App\Support;

use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Member;

/**
 * Premises stock ceiling — a COMPLIANCE signal (not merchandising). Warns when the on-site cannabis weight
 * exceeds `active_members × daily_limit × ceiling_days`. Returns the arithmetic (not a bare number), because
 * the figure is a setting and different sources quote different day counts (NOTES §A).
 *
 * Prompt 110 corrected which members count and what "on site" means:
 * - active members = members who are ACTIVE **and** hold an ACTIVE membership AT THIS location (was every
 *   member of the whole association, which credited each sede with the entire org's headroom).
 * - on-site = ALL batches physically present at the sede regardless of status/expiry — a quarantined, closed
 *   or expired batch is still on the premises and still counts legally (was OPEN-only).
 * - both settings reads (daily_limit_cg, stock_ceiling_days) resolve per-LOCATION, consistently.
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
        // Members with an ACTIVE membership AT THIS sede whose own status is ACTIVE (so an expelled/suspended
        // member with a stale membership row, or a lapsed membership, does not raise the ceiling).
        $activeMembers = (int) Member::query()->withoutGlobalScopes()
            ->whereNull('deleted_at') // withoutGlobalScopes strips SoftDeletingScope too (prompt 107)
            ->where('organisation_id', $location->organisation_id)
            ->where('status', MemberStatus::ACTIVE->value)
            ->whereHas('memberships', fn ($q) => $q->withoutGlobalScopes()
                ->where('location_id', $location->id)
                ->where('status', MembershipStatus::ACTIVE->value))
            ->count();

        // Both settings resolve per-LOCATION (prompt 110 made stock_ceiling_days consistent with daily_limit_cg,
        // which was already location-scoped — both are per-premises concepts).
        $scope = app(ActiveScope::class);
        $dailyLimitCg = (int) $scope->forLocation($location->id, fn () => Settings::get('daily_limit_cg'));
        $ceilingDays = (int) $scope->forLocation($location->id, fn () => Settings::get('stock_ceiling_days', 5));
        $ceilingCg = $activeMembers * $dailyLimitCg * $ceilingDays;

        // On-site gram-equivalent aggregates BOTH kinds and EVERY status: a WEIGHT batch's remaining_cg and a
        // UNIT batch's remaining_units × the genetic's grams_per_unit_cg — one compliance figure for what is
        // physically here (a depleted batch contributes 0 through its remaining columns).
        $onSiteCg = (int) Batch::query()->withoutGlobalScopes()
            ->join('genetics', 'batches.genetic_id', '=', 'genetics.id')
            ->whereNull('batches.deleted_at') // a soft-deleted batch is not on-site (prompt 107)
            ->where('batches.location_id', $location->id)
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
