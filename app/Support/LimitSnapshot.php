<?php

namespace App\Support;

/**
 * A member's consumption position at a moment — every figure the POS, PWA,
 * check-in and dashboard show comes from here (via ResolveMemberLimits), so they
 * can never disagree. All figures are integer centigrams.
 */
final class LimitSnapshot
{
    public function __construct(
        public readonly int $dailyLimitCg,
        public readonly int $monthlyLimitCg,
        public readonly int $dailyUsedCg,
        public readonly int $monthlyUsedCg,
    ) {}

    public function dailyRemainingCg(): int
    {
        return max(0, $this->dailyLimitCg - $this->dailyUsedCg);
    }

    public function monthlyRemainingCg(): int
    {
        return max(0, $this->monthlyLimitCg - $this->monthlyUsedCg);
    }

    /** Would dispensing this many centigrams breach the daily limit? */
    public function wouldBreachDaily(int $gramsCg): bool
    {
        return ($this->dailyUsedCg + $gramsCg) > $this->dailyLimitCg;
    }

    public function wouldBreachMonthly(int $gramsCg): bool
    {
        return ($this->monthlyUsedCg + $gramsCg) > $this->monthlyLimitCg;
    }

    public function allows(int $gramsCg): bool
    {
        return ! $this->wouldBreachDaily($gramsCg) && ! $this->wouldBreachMonthly($gramsCg);
    }

    /**
     * Prompt 306 — what is LEFT today, as a colour state: `ok` (green) above a quarter of the daily limit, `low` (amber)
     * above nothing, `empty` (red) at nothing. A zero allowance used to show in green, which says "fine" when nothing is
     * left. `$remainingCg` overrides the figure (the dispensary's "after this entry"); a negative one is `empty`.
     */
    public function dailyRemainingState(?int $remainingCg = null): string
    {
        $left = $remainingCg ?? $this->dailyRemainingCg();

        return match (true) {
            $left <= 0 => 'empty',
            $this->dailyLimitCg > 0 && $left * 4 <= $this->dailyLimitCg => 'low',
            default => 'ok',
        };
    }

    /** The counter's text colour for {@see self::dailyRemainingState()} — its existing tokens. */
    public static function dailyStateText(string $state): string
    {
        return match ($state) {
            'empty' => 'text-error',
            'low' => 'text-warning',
            default => 'text-success',
        };
    }

    /** Percent of the monthly allowance used (for the gauge). */
    public function monthlyPercent(): int
    {
        return $this->monthlyLimitCg > 0
            ? (int) floor($this->monthlyUsedCg / $this->monthlyLimitCg * 100)
            : 0;
    }

    /** Gauge colour state: neutral / warning / alert (never colour alone — always shown with a number). */
    public function gaugeState(): string
    {
        $percent = $this->monthlyPercent();

        return match (true) {
            $percent >= (int) Settings::get('gauge_alert_pct', 95) => 'alert',
            $percent >= (int) Settings::get('gauge_warning_pct', 70) => 'warning',
            default => 'neutral',
        };
    }
}
