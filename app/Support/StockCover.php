<?php

namespace App\Support;

use App\Enums\DispensationStatus;
use App\Models\DispensationLine;
use App\Models\Genetic;

/**
 * How long this genetic lasts at this sede **at the rate it is actually going** (prompt 216).
 *
 * **Two wrong defaults, facing opposite ways.** Prompt 54 shipped a flat 50 g: on a club under a legal stock
 * ceiling every genetic sat below it, so the badge was on permanently — furniture. Prompt 213 replaced it
 * with one member's daily allowance (350 cg): on the same seeded holdings (12.95 g – 32.66 g) **nothing
 * badges at all**, and a genetic only ever badges once it can no longer fill a single full order — at which
 * point it is not low, it is gone. Always-on and fires-too-late are the same failure wearing different
 * clothes.
 *
 * **The base was the problem, not the multiple.** An allowance measures what a member MAY take; consumption
 * measures what they DO. Any flat figure overstates urgency for a slow mover and understates it for the
 * popular genetic — which is precisely the one that runs out. And the owner's own question answers itself:
 * *low relative to the ceiling* is a club operating lawfully, so it is permanent and says nothing. **Low
 * relative to demand is the only thing worth painting.**
 *
 * So: **days of cover** = on-hand ÷ (trailing dispensing ÷ window). Trailing is real
 * `DispensationLine.grams_cg` over COMPLETED dispensations at this sede in the window — voided excluded, the
 * same way prompt 177 excludes them from a member's history, because a voided dispensation did not happen.
 *
 * **Bulk by design.** The dispensary grid renders on every basket change, so the trailing figures for the
 * whole grid are ONE grouped query ({@see self::trailingCgFor}), not one per card.
 */
class StockCover
{
    /** Trailing window, in days. A fortnight: long enough to smooth a quiet Tuesday, short enough to notice a trend. */
    public static function windowDays(?string $locationId = null): int
    {
        return max(1, (int) app(ActiveScope::class)->forLocation(
            $locationId,
            fn (): int => (int) Settings::get('stock_cover_window_days', 14),
        ));
    }

    /**
     * Below this many days of cover, the badge fires.
     *
     * **Two rather than one**, deliberately: a warning that arrives the day you run out is a notification.
     */
    public static function lowDays(?string $locationId = null): int
    {
        return max(1, (int) app(ActiveScope::class)->forLocation(
            $locationId,
            fn (): int => (int) Settings::get('stock_cover_low_days', 2),
        ));
    }

    /**
     * Trailing dispensed centigrams per genetic at this sede — **one grouped query for the whole grid**.
     *
     * Unit genetics are summed on the same `grams_cg` column: `CommitDispensation` writes the gram
     * equivalent of a unit line there, which is what makes one figure serve both kinds — the same rule
     * `Genetic::onHandCgAt()` documents on the stock side.
     *
     * @param  list<string>  $geneticIds
     * @return array<string, int>
     */
    public static function trailingCgFor(array $geneticIds, string $locationId, ?int $windowDays = null): array
    {
        if ($geneticIds === []) {
            return [];
        }

        $since = now()->subDays($windowDays ?? self::windowDays($locationId));

        return DispensationLine::query()->withoutGlobalScopes()
            ->join('dispensations', 'dispensations.id', '=', 'dispensation_lines.dispensation_id')
            ->whereIn('dispensation_lines.genetic_id', $geneticIds)
            ->where('dispensations.location_id', $locationId)
            ->where('dispensations.status', DispensationStatus::COMPLETED->value)
            ->where('dispensations.dispensed_at', '>=', $since)
            ->groupBy('dispensation_lines.genetic_id')
            ->selectRaw('dispensation_lines.genetic_id as genetic_id, SUM(dispensation_lines.grams_cg) as cg')
            ->pluck('cg', 'genetic_id')
            ->map(fn ($cg): int => (int) $cg)
            ->all();
    }

    /**
     * When each genetic was FIRST dispensed at this sede — one query, for the thin-history test.
     *
     * A genetic whose first sale here falls inside the window has not had a full window to average over, so
     * its rate is an artefact of when it arrived rather than a measure of demand.
     *
     * @param  list<string>  $geneticIds
     * @return array<string, string>
     */
    public static function firstDispensedAtFor(array $geneticIds, string $locationId): array
    {
        if ($geneticIds === []) {
            return [];
        }

        return DispensationLine::query()->withoutGlobalScopes()
            ->join('dispensations', 'dispensations.id', '=', 'dispensation_lines.dispensation_id')
            ->whereIn('dispensation_lines.genetic_id', $geneticIds)
            ->where('dispensations.location_id', $locationId)
            ->where('dispensations.status', DispensationStatus::COMPLETED->value)
            ->groupBy('dispensation_lines.genetic_id')
            ->selectRaw('dispensation_lines.genetic_id as genetic_id, MIN(dispensations.dispensed_at) as first_at')
            ->pluck('first_at', 'genetic_id')
            ->map(fn ($at): string => (string) $at)
            ->all();
    }

    /**
     * Days of cover, or null when there is no rate to divide by.
     *
     * The zero-rate case is guarded **before** the division rather than after: `∞ días` is not a thing to
     * render, and a division by zero is not a thing to catch.
     */
    public static function days(int $onHandCg, int $trailingCg, int $windowDays): ?float
    {
        if ($trailingCg <= 0 || $windowDays <= 0) {
            return null;
        }

        return $onHandCg / ($trailingCg / $windowDays);
    }

    /**
     * The whole verdict for one genetic — **the single source both surfaces read**.
     *
     * Precedence, decided rather than discovered:
     *
     *  1. **Explicit overrides win, as absolute floors.** A non-zero per-sede `low_stock_threshold_cg` on
     *     `GeneticPrice`, then the org setting: a club that has stated a figure has stated it, and 213
     *     preserved that precedence for the same reason.
     *  2. **Thin history** — never dispensed here, or first dispensed here inside the window — falls back to
     *     213's allowance-derived figure. A new genetic must not read as infinitely covered.
     *  3. **Zero trailing with stock on hand: no badge.** Nothing is running out; it is not moving. That may
     *     well be its own problem, but it is not this badge's problem, and painting it here would put the
     *     badge back on permanently for every slow mover — which is the failure this branch exists to end.
     *  4. Otherwise: cover, against the day threshold.
     *
     * @return array{low: bool, days: ?float, basis: string}
     */
    public static function verdict(Genetic $genetic, string $locationId, int $onHandCg, ?int $trailingCg = null, ?string $firstDispensedAt = null): array
    {
        if ($onHandCg <= 0) {
            return ['low' => false, 'days' => null, 'basis' => 'empty'];
        }

        $explicit = $genetic->explicitLowStockThresholdCg($locationId);

        if ($explicit !== null) {
            return ['low' => $onHandCg <= $explicit, 'days' => null, 'basis' => 'explicit'];
        }

        $window = self::windowDays($locationId);
        $trailingCg ??= self::trailingCgFor([$genetic->id], $locationId, $window)[$genetic->id] ?? 0;

        if (func_num_args() < 5) {
            $firstDispensedAt = self::firstDispensedAtFor([$genetic->id], $locationId)[$genetic->id] ?? null;
        }

        $thin = $firstDispensedAt === null || strtotime($firstDispensedAt) >= now()->subDays($window)->timestamp;

        if ($thin) {
            return [
                'low' => $onHandCg <= Genetic::derivedLowStockThresholdCg($locationId),
                'days' => null,
                'basis' => 'thin-history',
            ];
        }

        $days = self::days($onHandCg, $trailingCg, $window);

        if ($days === null) {
            return ['low' => false, 'days' => null, 'basis' => 'not-moving'];
        }

        return ['low' => $days < self::lowDays($locationId), 'days' => $days, 'basis' => 'cover'];
    }

    /** The staff-facing figure: *"≈2 días"*. Never rendered to a member — see `Genetic::availabilityAt()`. */
    public static function label(?float $days): ?string
    {
        if ($days === null) {
            return null;
        }

        return __('≈:n días', ['n' => max(0, (int) floor($days))]);
    }
}
