<?php

namespace App\Actions\Dispensing;

use App\Enums\DispensationStatus;
use App\Enums\MembershipStatus;
use App\Models\DispensationLine;
use App\Models\Location;
use App\Models\Member;
use App\Models\MembershipTier;
use App\Support\ActiveScope;
use App\Support\BusinessDay;
use App\Support\LimitSnapshot;
use App\Support\Settings;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * THE single place limit arithmetic lives. Returns a member's daily/monthly limit,
 * used and remaining at a moment. Limit precedence: per-member override → active
 * membership tier → location → org default (Settings resolves location→org). "Today"
 * and "this month" come from BusinessDay (location timezone + cutoff), never
 * now()->startOfDay(). Used is computed LIVE from the dispensation ledger (COMPLETED
 * only, so voided/corrected grams are released automatically) — never a cached counter.
 */
class ResolveMemberLimits
{
    public function handle(Member $member, Location $location, DateTimeInterface|string|null $at = null): LimitSnapshot
    {
        $daily = $this->resolveLimit($member, $location, 'daily_limit_cg', fn (MembershipTier $t) => $t->daily_limit_cg);
        $monthly = $this->resolveLimit($member, $location, 'monthly_limit_cg', fn (MembershipTier $t) => $t->monthly_limit_cg);

        [$dayStart, $dayEnd] = BusinessDay::window($location, $at);
        [$monthStart, $monthEnd] = $this->monthWindow($location, $at);

        return new LimitSnapshot(
            dailyLimitCg: $daily,
            monthlyLimitCg: $monthly,
            dailyUsedCg: $this->usedBetween($member, $dayStart, $dayEnd),
            monthlyUsedCg: $this->usedBetween($member, $monthStart, $monthEnd),
        );
    }

    /**
     * @param  callable(MembershipTier): ?int  $tierValue
     */
    private function resolveLimit(Member $member, Location $location, string $memberField, callable $tierValue): int
    {
        if ($member->{$memberField} !== null) {
            return (int) $member->{$memberField};
        }

        $tier = $this->activeTier($member, $location);
        if ($tier !== null && $tierValue($tier) !== null) {
            return (int) $tierValue($tier);
        }

        // location → org default (Settings resolves precedence when scoped to the location).
        return (int) app(ActiveScope::class)->forLocation(
            $location->id,
            fn () => Settings::get($memberField),
        );
    }

    private function activeTier(Member $member, Location $location): ?MembershipTier
    {
        return $member->memberships()->withoutGlobalScopes()
            ->where('location_id', $location->id)
            ->where('status', MembershipStatus::ACTIVE->value)
            ->latest('id')
            ->first()?->tier;
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function monthWindow(Location $location, DateTimeInterface|string|null $at): array
    {
        $businessDate = BusinessDay::date($location, $at);
        // Boundaries computed in the location tz, then expressed in the app (storage)
        // timezone so the whereBetween below matches app-tz-stored dispensed_at values
        // (same instant-preserving normalisation as BusinessDay::window()).
        $storageTz = config('app.timezone') ?: 'UTC';

        if (Settings::get('monthly_window', 'calendar') === 'rolling30') {
            return [
                $businessDate->copy()->subDays(29)->startOfDay()->setTimezone($storageTz),
                $businessDate->copy()->addDay()->startOfDay()->setTimezone($storageTz),
            ];
        }

        return [
            $businessDate->copy()->startOfMonth()->setTimezone($storageTz),
            $businessDate->copy()->startOfMonth()->addMonth()->setTimezone($storageTz),
        ];
    }

    private function usedBetween(Member $member, CarbonInterface $start, CarbonInterface $end): int
    {
        return (int) DispensationLine::query()->withoutGlobalScopes()
            ->whereHas('dispensation', fn ($q) => $q
                ->where('member_id', $member->id)
                ->where('status', DispensationStatus::COMPLETED->value)
                ->whereBetween('dispensed_at', [$start, $end]))
            ->sum('grams_cg');
    }
}
