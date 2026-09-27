<?php

use App\Models\Location;
use App\Support\Period;
use Carbon\CarbonImmutable;

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

if (! function_exists('local_datetime')) {
    /**
     * A stored (UTC) timestamp as the sede's wall-clock time (prompt 271). Storage stays UTC; every time a person reads —
     * a receipt, the Registro, the till — is the sede's local time, never UTC printed as if it were. The sede defaults to
     * the one in scope; without one, the app timezone.
     */
    function local_datetime(mixed $value, string $format = 'd/m/Y H:i', ?Location $location = null): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $storageTz = config('app.timezone') ?: 'UTC';
        $instant = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value, $storageTz);

        return $instant->setTimezone(Period::displayTimezone($location))->format($format);
    }
}
