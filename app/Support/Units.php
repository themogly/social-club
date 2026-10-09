<?php

namespace App\Support;

/**
 * Prompt 375 — a count of units, the one way it is written: «1 ud.» / «3 uds», «1 unit» / «3 units». A bare `__('uds')` after a
 * number printed «1 units» on the receipt (and «1 uds» in Spanish).
 */
final class Units
{
    public static function count(int $n): string
    {
        return trans_choice(':count ud.|:count uds', $n, ['count' => $n]);
    }

    /** «+3 uds» / «-1 ud.»: a signed difference, the plural chosen on its size. */
    public static function signed(int $n): string
    {
        return ($n > 0 ? '+' : ($n < 0 ? '-' : '')).self::count(abs($n));
    }
}
