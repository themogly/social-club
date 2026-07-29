<?php

if (! function_exists('round_half_up')) {
    /**
     * The one shared rounding rule for the whole app: round half away from zero
     * ("half up"). Used ONLY at the conversion edge (euros → cents, grams →
     * centigrams) and the result is immediately cast to an integer minor unit —
     * never used for ongoing money/weight arithmetic, which is always integer.
     */
    function round_half_up(int|float|string $value, int $precision = 0): float
    {
        return round((float) $value, $precision, PHP_ROUND_HALF_UP);
    }
}
