<?php

namespace App\Support;

use App\Rules\GramAmount;
use InvalidArgumentException;

/**
 * Immutable weight value object. Stored and reasoned about as **integer
 * centigrams** (1 g = 100 cg, i.e. 0.01 g precision — matches scale hardware)
 * everywhere; grams appear only at the input/display edge. A float is a bug.
 */
final class Weight
{
    public function __construct(public readonly int $centigrams) {}

    public static function fromCentigrams(int $centigrams): self
    {
        return new self($centigrams);
    }

    /**
     * Parse a grams amount from the edge (weight entry, forecast, intake) to centigrams, rounding half-up at the
     * conversion boundary.
     *
     * THE STRING CONTRACT (prompt 257) — no guessing. A string is accepted only when it can mean exactly one
     * number: digits, optionally followed by ONE separator (`,` or `.`) and ONE or TWO decimal digits — "1000",
     * "3,5", "3.50", "1000,5". Both separators are read as the decimal because neither convention groups by one
     * or two digits (es groups `1.000`, en groups `1,000`), so "3,5" and "3.5" are the same number wherever the
     * tablet is. Anything else throws: a separator in a thousands position ("1.000", "1,000"), two separators
     * ("1.000,00"), spaces, a sign, more than two decimals (beyond our 0.01 g precision). This replaced a
     * `str_replace(',', '.')` that read BOTH "1.000" and "1,000" as ONE gram — a thousand grams of opening
     * stock became one. A value that cannot be read unambiguously is refused, never rounded to a plausible
     * wrong answer; form fields validate with {@see GramAmount} so the operator sees why.
     *
     * Ints and floats are programmatic (seeders, computed values) and are taken as they are.
     */
    public static function fromGrams(int|float|string $grams): self
    {
        if (is_string($grams)) {
            $canonical = self::canonicalGrams($grams);
            if ($canonical === null) {
                throw new InvalidArgumentException("Not an unambiguous gram amount: {$grams}");
            }
            $grams = $canonical;
        }

        return new self((int) round_half_up((float) $grams * 100));
    }

    /**
     * A typed gram amount as a canonical dot-decimal string ("1000", "3.5"), or null when it is not one
     * unambiguous number — see {@see fromGrams()} for the contract. The one parser; the validation rule and
     * the counter's reweigh ask it rather than re-deriving the rule.
     */
    public static function canonicalGrams(string $typed): ?string
    {
        if (preg_match('/^(\d+)(?:[.,](\d{1,2}))?$/', trim($typed), $m) !== 1) {
            return null;
        }

        return isset($m[2]) ? $m[1].'.'.$m[2] : $m[1];
    }

    public function grams(): float
    {
        return $this->centigrams / 100;
    }

    public function add(self $other): self
    {
        return new self($this->centigrams + $other->centigrams);
    }

    public function subtract(self $other): self
    {
        return new self($this->centigrams - $other->centigrams);
    }

    public function isNegative(): bool
    {
        return $this->centigrams < 0;
    }

    public function equals(self $other): bool
    {
        return $this->centigrams === $other->centigrams;
    }

    /**
     * Display edge only, e.g. "3,50 g" in Spanish.
     */
    public function formatted(?string $locale = null): string
    {
        return number_format($this->grams(), 2, (($locale ?? app()->getLocale()) === 'es') ? ',' : '.', '').' g';
    }

    public function __toString(): string
    {
        return (string) $this->centigrams;
    }
}
