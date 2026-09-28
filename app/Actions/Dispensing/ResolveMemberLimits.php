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
    /**
     * `$defaults` stands in for the org/sede default limits (`daily_limit_cg`, `monthly_limit_cg`) — how the switch-on
     * modal counts, with THIS resolver, who a proposed default would put over the month (prompt 296).
     *
     * @param  array{daily_limit_cg?: int, monthly_limit_cg?: int}  $defaults
     */
    public function handle(Member $member, Location $location, DateTimeInterface|string|null $at = null, array $defaults = []): LimitSnapshot
    {
        $daily = $this->resolveLimit($member, $location, 'daily_limit_cg', fn (MembershipTier $t) => $t->daily_limit_cg, $defaults);
        $monthly = $this->resolveLimit($member, $location, 'monthly_limit_cg', fn (MembershipTier $t) => $t->monthly_limit_cg, $defaults);

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
    /**
     * The limits to SHOW — none at all while the owner has switched limits off (prompt 296): the counter's allowance, the
     * month bar and the limit-greyed presets all read this, so they disappear together.
     */
    public function shown(Member $member, Location $location): ?LimitSnapshot
    {
        return Settings::limitsEnabled() ? $this->handle($member, $location) : null;
    }

    /** @param  array{daily_limit_cg?: int, monthly_limit_cg?: int}  $defaults */
    private function resolveLimit(Member $member, Location $location, string $memberField, callable $tierValue, array $defaults = []): int
    {
        if ($member->{$memberField} !== null) {
            return (int) $member->{$memberField};
        }

        $tier = $this->activeTier($member, $location);
        if ($tier !== null && $tierValue($tier) !== null) {
            return (int) $tierValue($tier);
        }

        if (isset($defaults[$memberField])) {
            return (int) $defaults[$memberField];
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
        // Prompt 271 — the BUSINESS month (starting at the cutoff on the 1st), the same window every report uses. It
        // used to start at local midnight, so the cap and the Registro disagreed about a 01:30 dispensation on the 1st.
        return Settings::get('monthly_window', 'calendar') === 'rolling30'
            ? BusinessDay::rolling30Window($location, $at)
            : BusinessDay::periodWindow($location, 'month', $at);
    }

    private function usedBetween(Member $member, CarbonInterface $start, CarbonInterface $end): int
    {
        return (int) DispensationLine::query()->withoutGlobalScopes()
            ->whereHas('dispensation', fn ($q) => $q
                ->where('member_id', $member->id)
                ->where('status', DispensationStatus::COMPLETED->value)
                // Half-open (prompt 271): a row stamped exactly on a boundary belongs to one window, not both.
                ->where('dispensed_at', '>=', $start)
                ->where('dispensed_at', '<', $end))
            ->sum('grams_cg');
    }
}
