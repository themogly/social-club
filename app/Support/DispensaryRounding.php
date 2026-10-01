<?php

namespace App\Support;

/**
 * Prompt 350 (Aaron: "Local discount should round to a whole number rather than be exact, to save needing so much float")
 * — the ONE rounding rule for the dispensary part of a visit. The counter's chargeable total (333's single function)
 * and the dispensation writer both call it, so what is shown, filled in by «Justo» and committed is one figure.
 *
 *  - Mode (*Redondear el total con descuento*): `nearest` (default, half up) | `down` (always in the member's favour) |
 *    `none` (exact cents, as before).
 *  - Scope (*Aplicar a*): `local` (default — only when a line's discount is a LOCAL one) | `any` (any discount) | `all`
 *    (every contribution, discounted or not).
 *  - Once per dispensary total, never per line; the difference is spread over the lines by largest remainder, so the
 *    lines still add up. A manager's price adjustment (333) wins: it is never rounded on top. Money only — grams,
 *    limits, stock and the register are untouched.
 */
class DispensaryRounding
{
    public static function mode(): string
    {
        $mode = (string) Settings::get('discount_rounding', 'nearest');

        return in_array($mode, ['nearest', 'down', 'none'], true) ? $mode : 'nearest';
    }

    public static function scope(): string
    {
        $scope = (string) Settings::get('discount_rounding_scope', 'local');

        return in_array($scope, ['local', 'any', 'all'], true) ? $scope : 'local';
    }

    /**
     * Does rounding apply to this basket?
     *
     * @param  list<?string>  $discountKinds  each line's applied discount kind (a DiscountKind value, TIER, or null)
     */
    public static function applies(array $discountKinds): bool
    {
        if (self::mode() === 'none' || $discountKinds === []) {
            return false;
        }

        return match (self::scope()) {
            'all' => true,
            'any' => collect($discountKinds)->contains(fn (?string $kind): bool => $kind !== null),
            default => in_array('LOCAL', $discountKinds, true),
        };
    }

    /** The whole-euro figure: nearest (half up) or down. */
    public static function round(int $cents): int
    {
        $cents = max(0, $cents);

        // Integer arithmetic only — never a float in money. Half up: +50 then truncate.
        return self::mode() === 'down' ? intdiv($cents, 100) * 100 : intdiv($cents + 50, 100) * 100;
    }

    /**
     * The total to charge for a basket: rounded when it applies, as it is otherwise.
     *
     * @param  list<?string>  $discountKinds
     */
    public static function total(int $cents, array $discountKinds): int
    {
        return self::applies($discountKinds) ? self::round($cents) : $cents;
    }

    /**
     * Spread a (signed) difference over line totals in proportion to them, largest remainder first, in whole cents —
     * the same way the eighth break is spread — so the adjusted lines add up exactly to the rounded total.
     *
     * @param  list<int>  $totals
     * @return list<int> the adjustment per line
     */
    public static function spread(array $totals, int $delta): array
    {
        $count = count($totals);
        $adjust = array_fill(0, $count, 0);
        if ($delta === 0 || $count === 0) {
            return $adjust;
        }
        $sum = array_sum($totals);
        if ($sum <= 0) {
            $adjust[0] = $delta;

            return $adjust;
        }

        $sign = $delta < 0 ? -1 : 1;
        $magnitude = abs($delta);
        $remainders = [];
        $given = 0;
        foreach ($totals as $i => $total) {
            $share = $magnitude * max(0, $total);
            $adjust[$i] = intdiv($share, $sum);
            $remainders[$i] = $share % $sum; // integer remainder: who is owed the next cent
            $given += $adjust[$i];
        }
        arsort($remainders);
        foreach (array_keys($remainders) as $i) {
            if ($given >= $magnitude) {
                break;
            }
            $adjust[$i]++;
            $given++;
        }

        return array_map(fn (int $a): int => $a * $sign, $adjust);
    }
}
