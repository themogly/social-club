<?php

namespace App\Support;

/**
 * THE rule for a number a person typed (grams, euros, percentages): one canonical dot-decimal string, or null when it
 * could mean two different numbers. Accepted:
 *   · digits, optionally ONE separator (`,` or `.`) and one or two decimals — "1000", "3,5", "3.50" (prompt 257);
 *   · prompt 306 — the FULLY written forms, which can only mean one number: Spanish "1.000,01" (dots in thousands
 *     groups, comma decimal) and English "1,000.01". Both separators present, the last one is the decimal.
 * Refused, as before: a lone separator in a thousands position ("1.000", "1,000" — a thousand, or one?), mixed or
 * misplaced groups, spaces, signs, more than two decimals. The panel's decimal fields, the counter's money and weight
 * inputs and `Weight::fromGrams()` all read through here, so the panel and the counter can never disagree.
 */
final class TypedNumber
{
    public static function canonical(string $typed, int $maxIntegerDigits = 12): ?string
    {
        $typed = trim($typed);

        $integer = match (true) {
            preg_match('/^(\d+)(?:[.,](\d{1,2}))?$/', $typed, $m) === 1 => $m[1],
            preg_match('/^(\d{1,3}(?:\.\d{3})+),(\d{1,2})$/', $typed, $m) === 1 => str_replace('.', '', $m[1]),
            preg_match('/^(\d{1,3}(?:,\d{3})+)\.(\d{1,2})$/', $typed, $m) === 1 => str_replace(',', '', $m[1]),
            default => null,
        };

        if ($integer === null || strlen($integer) > $maxIntegerDigits) {
            return null;
        }

        return isset($m[2]) ? $integer.'.'.$m[2] : $integer;
    }

    /**
     * Prompt 382 — what was typed as euros (or a field's live numeric state), in integer cents; null when empty or
     * unreadable. Signs are refused by `canonical()`, so a negative amount is null too.
     */
    public static function cents(mixed $typed): ?int
    {
        $number = match (true) {
            is_int($typed), is_float($typed) => $typed < 0 ? null : (string) $typed,
            is_string($typed) => self::canonical($typed),
            default => null,
        };

        return $number === null ? null : Money::fromEuros($number)->cents;
    }
}
