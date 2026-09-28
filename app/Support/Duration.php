<?php

namespace App\Support;

/**
 * How worked time reads (prompt 285): one shift as "7 h 30 min", a total over days as decimal hours in the locale's
 * format ("152,5 h"). Minutes are integers throughout; this is the display edge.
 */
class Duration
{
    public static function format(int $minutes): string
    {
        $minutes = max(0, $minutes);

        return sprintf('%d h %02d min', intdiv($minutes, 60), $minutes % 60);
    }

    /** Decimal hours, one decimal, the trailing ",0" dropped: 9150 min → "152,5 h", 480 min → "8 h". */
    public static function hours(int $minutes): string
    {
        return self::decimalHours($minutes).' h';
    }

    /** The bare number, for a chart axis or a spreadsheet cell. */
    public static function decimalHours(int $minutes, ?string $decimal = null): string
    {
        $decimal ??= app()->getLocale() === 'es' ? ',' : '.';
        $value = round(max(0, $minutes) / 60, 1);

        return floor($value) == $value ? (string) (int) $value : number_format($value, 1, $decimal, '');
    }
}
