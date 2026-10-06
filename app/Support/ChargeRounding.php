<?php

namespace App\Support;

use App\Actions\RecordAuditLog;
use App\Models\Location;
use App\Models\User;

/**
 * Prompt 355 — the weight a member PAYS for is rounded to the half gram (agreed between Ben and the club): "if you sell
 * 0.2 g they pay for half a gram; if you sell 1.1 g they pay for 1 g". Every weight line therefore has two weights:
 *
 *  - **weighed**: exactly what left the jar — stock, the member's limits, the legal ceiling, the register and the recount
 *    read only this, unchanged;
 *  - **charged**: the weighed grams rounded to the nearest 0.5 g, never under 0.5 g, exact halves DOWN (Ben's decisions,
 *    6 Oct 2026: "nearest", and ties down — 0.75 → 0.5, 1.25 → 1.0). Only the PRICE reads it, eighths included.
 *
 * Applied once per weight line, on its total, before the eighth break (ResolvePrice). Unit products are unaffected.
 *
 * The switch: staff may turn it off at the counter. The choice is kept in the server session, keyed by the PIN operator
 * and the sede — so it survives visits, a member change, an idle lock and the same person's PIN again, never carries over
 * to someone else, and ends with the browser session. It starts from the sede's «Redondeo del peso cobrado» (on by
 * default). The commit reads it from here, never from the request.
 */
final class ChargeRounding
{
    public const STEP_CG = 50;

    /** The charged centigrams for a weighed amount (rounding on). */
    public static function charged(int $weighedCg): int
    {
        if ($weighedCg <= 0) {
            return 0;
        }
        $steps = intdiv($weighedCg, self::STEP_CG);
        $rest = $weighedCg % self::STEP_CG;
        $rounded = ($rest * 2 > self::STEP_CG ? $steps + 1 : $steps) * self::STEP_CG; // an exact half rounds DOWN

        return max(self::STEP_CG, $rounded);
    }

    /**
     * The charged centigrams of each batch part of a line whose weighed parts are `$weighedParts`, adding up to exactly
     * `$chargedCg`: the difference is applied from the LAST part backwards, never taking a part below zero.
     *
     * @param  list<int>  $weighedParts
     * @return list<int>
     */
    public static function overParts(array $weighedParts, int $chargedCg): array
    {
        $parts = $weighedParts;
        $delta = $chargedCg - array_sum($parts);
        if ($parts === []) {
            return [];
        }
        if ($delta >= 0) {
            $parts[count($parts) - 1] += $delta;

            return $parts;
        }
        for ($i = count($parts) - 1; $i >= 0 && $delta < 0; $i--) {
            $take = min($parts[$i], -$delta);
            $parts[$i] -= $take;
            $delta += $take;
        }

        return $parts;
    }

    /** Is rounding on for this person at this sede? Their choice this session, else the sede's default. */
    public static function enabled(?User $operator, ?string $locationId): bool
    {
        $chosen = $operator !== null && $locationId !== null ? session(self::key($operator, $locationId)) : null;

        return is_bool($chosen) ? $chosen : self::sedeDefault($locationId);
    }

    public static function sedeDefault(?string $locationId): bool
    {
        return (bool) Settings::get('charge_rounding_enabled', true, $locationId);
    }

    /** Flip it for this person at this sede (anyone who can dispense), audited. */
    public static function set(User $operator, Location $location, bool $on): void
    {
        session([self::key($operator, (string) $location->getKey()) => $on]);
        (new RecordAuditLog)->handle('counter.rounding.toggled', $location, null, [
            'operator_id' => $operator->id,
            'location_id' => $location->id,
            'on' => $on,
        ]);
    }

    private static function key(User $operator, string $locationId): string
    {
        return 'counter.charge_rounding.'.$operator->getKey().'.'.$locationId;
    }
}
