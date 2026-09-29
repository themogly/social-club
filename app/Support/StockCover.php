<?php

namespace App\Support;

use App\Enums\DispensationStatus;
use App\Models\Batch;
use App\Models\DispensationLine;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Scopes\OrganisationScope;
use Illuminate\Database\Eloquent\Collection;

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
     * Dispensable stock per genetic at a sede, in ONE grouped query (prompts 269/273): centigrams and units, over the
     * batches `Batch::scopeDispensable` admits — the same rule `SelectBatch` serves from.
     *
     * @param  list<Genetic>  $genetics
     * @return array<string, array{cg: int, units: int}>
     */
    public static function stockFor(array $genetics, string $locationId): array
    {
        if ($genetics === []) {
            return [];
        }

        $sums = Batch::query()->withoutGlobalScopes()
            ->whereIn('genetic_id', array_map(fn (Genetic $genetic): string => $genetic->id, $genetics))
            ->where('location_id', $locationId)
            ->dispensable($locationId)
            ->groupBy('genetic_id')
            ->selectRaw('genetic_id, COALESCE(SUM(remaining_cg), 0) AS cg, COALESCE(SUM(remaining_units), 0) AS units')
            ->toBase()->get()->keyBy('genetic_id');

        $stock = [];
        foreach ($genetics as $genetic) {
            $row = $sums->get($genetic->id);
            $stock[$genetic->id] = ['cg' => (int) ($row->cg ?? 0), 'units' => (int) ($row->units ?? 0)];
        }

        return $stock;
    }

    /**
     * On-hand gram-equivalent per genetic — {@see Genetic::onHandCgAt()} for a whole list. A UNIT genetic reports units ×
     * grams-per-unit so one figure serves both kinds.
     *
     * @param  list<Genetic>  $genetics
     * @return array<string, int>
     */
    public static function onHandCgFor(array $genetics, string $locationId): array
    {
        $stock = self::stockFor($genetics, $locationId);
        $onHand = [];

        foreach ($genetics as $genetic) {
            $onHand[$genetic->id] = $genetic->isUnitType()
                ? $stock[$genetic->id]['units'] * (int) $genetic->grams_per_unit_cg
                : $stock[$genetic->id]['cg'];
        }

        return $onHand;
    }

    /**
     * How many of these genetics are low at a sede — `verdict()` over a whole list in a fixed number of
     * queries (prompt 269). The counter hub renders this on every navigation, so a per-genetic query here
     * would grow with the catalogue on the page that renders most.
     *
     * @param  Collection<int, Genetic>  $genetics  with `prices` eager-loaded for this sede
     */
    public static function lowCountAt(Collection $genetics, string $locationId): int
    {
        return count(self::lowIdsAt($genetics, $locationId));
    }

    /**
     * WHICH of these genetics are low at a sede — the ids behind {@see self::lowCountAt()}, for the Genéticas "Stock bajo"
     * filter the dashboard alert lands on (prompt 273: an alert whose destination cannot say which ones is a dead end).
     *
     * @param  Collection<int, Genetic>  $genetics  with `prices` eager-loaded for this sede
     * @return list<string>
     */
    public static function lowIdsAt(Collection $genetics, string $locationId): array
    {
        $ids = $genetics->modelKeys();
        $onHand = self::onHandCgFor($genetics->values()->all(), $locationId);
        $trailing = self::trailingCgFor($ids, $locationId);
        $firstDispensed = self::firstDispensedAtFor($ids, $locationId);

        return $genetics->filter(fn (Genetic $genetic): bool => self::verdictWith(
            $genetic, $locationId, $onHand[$genetic->id] ?? 0,
            $trailing[$genetic->id] ?? 0,
            $firstDispensed[$genetic->id] ?? null,
        )['low'])->map(fn (Genetic $genetic): string => $genetic->id)->values()->all();
    }

    /**
     * Prompt 311 — the strains at a sede that need attention: low by {@see self::verdictWith()} (the same verdict the
     * dashboard alert and *Stock bajo* use), OR sellable here with nothing left (`basis: empty`, which the badge treats as
     * "not low" — for an alert, running out is worse than low, not recovered). With the figures the message quotes.
     *
     * @param  Collection<int, Genetic>  $genetics  with `prices` eager-loaded for this sede
     * @return array<string, array{on_hand_cg: int, days: ?float, out: bool}>
     */
    public static function attentionAt(Collection $genetics, string $locationId): array
    {
        $ids = $genetics->modelKeys();
        $onHand = self::onHandCgFor($genetics->values()->all(), $locationId);
        $trailing = self::trailingCgFor($ids, $locationId);
        $firstDispensed = self::firstDispensedAtFor($ids, $locationId);

        $attention = [];
        foreach ($genetics as $genetic) {
            $verdict = self::verdictWith($genetic, $locationId, $onHand[$genetic->id] ?? 0, $trailing[$genetic->id] ?? 0, $firstDispensed[$genetic->id] ?? null);
            if ($verdict['low'] || $verdict['basis'] === 'empty') {
                $attention[$genetic->id] = ['on_hand_cg' => $onHand[$genetic->id] ?? 0, 'days' => $verdict['days'], 'out' => $verdict['basis'] === 'empty'];
            }
        }

        return $attention;
    }

    /**
     * The genetics low at any of these sedes (the active sede, or every sede of an owner's rollup).
     *
     * @param  iterable<Location>  $locations
     * @return list<string>
     */
    public static function lowGeneticIds(iterable $locations): array
    {
        $ids = [];
        foreach ($locations as $location) {
            $ids = array_merge($ids, self::lowIdsAt(self::sellableWithPricesAt($location), $location->id));
        }

        return array_values(array_unique($ids));
    }

    /** @return Collection<int, Genetic> the genetics sellable at a sede, with that sede's prices eager-loaded */
    public static function sellableWithPricesAt(Location $location): Collection
    {
        return Genetic::query()->withoutGlobalScope(OrganisationScope::class)
            ->where('organisation_id', $location->organisation_id)
            ->sellableAt($location->id)
            ->with(['prices' => fn ($query) => $query->withoutGlobalScopes()->where('location_id', $location->id)])
            ->get();
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
    public static function verdict(Genetic $genetic, string $locationId, int $onHandCg): array
    {
        return self::decide($genetic, $locationId, $onHandCg, function () use ($genetic, $locationId): array {
            return [
                self::trailingCgFor([$genetic->id], $locationId, self::windowDays($locationId))[$genetic->id] ?? 0,
                self::firstDispensedAtFor([$genetic->id], $locationId)[$genetic->id] ?? null,
            ];
        });
    }

    /**
     * {@see self::verdict()} with the history already in hand — for the bulk callers (the counter grid, the alert) that
     * read trailing consumption and first-sale dates for the whole list in two grouped queries. Explicit rather than
     * the old `func_num_args()` check that told "not passed" from "passed null" (prompt 273).
     *
     * @return array{low: bool, days: ?float, basis: string}
     */
    public static function verdictWith(Genetic $genetic, string $locationId, int $onHandCg, int $trailingCg, ?string $firstDispensedAt): array
    {
        return self::decide($genetic, $locationId, $onHandCg, fn (): array => [$trailingCg, $firstDispensedAt]);
    }

    /**
     * @param  \Closure(): array{0: int, 1: ?string}  $history  trailing grams and first-sale date — only asked for once
     *                                                          the cheaper rules have not decided
     * @return array{low: bool, days: ?float, basis: string}
     */
    private static function decide(Genetic $genetic, string $locationId, int $onHandCg, \Closure $history): array
    {
        if ($onHandCg <= 0) {
            return ['low' => false, 'days' => null, 'basis' => 'empty'];
        }

        $explicit = $genetic->explicitLowStockThresholdCg($locationId);

        if ($explicit !== null) {
            return ['low' => $onHandCg <= $explicit, 'days' => null, 'basis' => 'explicit'];
        }

        $window = self::windowDays($locationId);
        [$trailingCg, $firstDispensedAt] = $history();

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
