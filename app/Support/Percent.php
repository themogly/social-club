<?php

namespace App\Support;

/**
 * A percentage for a person to read, with the locale's decimal separator ("12,50 %" in Spanish) — the twin of
 * {@see Weight::formatted()} (prompt 306: panel tables showed English "12.50%" beside Spanish prices).
 */
final class Percent
{
    public static function formatted(float $value, int $decimals = 2, ?string $locale = null): string
    {
        return number_format($value, $decimals, (($locale ?? app()->getLocale()) === 'es') ? ',' : '.', '').' %';
    }
}
