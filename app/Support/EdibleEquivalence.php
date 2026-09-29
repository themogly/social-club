<?php

namespace App\Support;

/**
 * Prompt 326 — what one edible COUNTS as, in grams, for the consumption limits and the stock ceiling: its THC (mg) over
 * the club's `edible_thc_mg_per_gram` (default 150 ≈ flower at 15 % THC — OVERNIGHT-DEFAULT, to confirm with the
 * gestor: how edibles count against a gram limit is a policy and legal question, not a technical one).
 *
 * THE one conversion — the form's live line, the observer, the setting's recalculation and the migration all use it.
 * Integer arithmetic, half up, and never below 1 cg: an edible never counts as nothing.
 */
final class EdibleEquivalence
{
    public const DEFAULT_MG_PER_GRAM = 150;

    /** A sanity cap on one unit's THC (a setting-free constant): above it the figure is almost certainly a typo. */
    public const MAX_THC_MG_PER_UNIT = 1000;

    public static function mgPerGram(): int
    {
        return max(1, (int) Settings::get('edible_thc_mg_per_gram', self::DEFAULT_MG_PER_GRAM));
    }

    /** round_half_up(thcMg × 100 / mgPerGram), in centigrams, at least 1. */
    public static function gramsCg(int $thcMg, ?int $mgPerGram = null): int
    {
        $per = max(1, $mgPerGram ?? self::mgPerGram());

        return max(1, intdiv(2 * $thcMg * 100 + $per, 2 * $per));
    }
}
