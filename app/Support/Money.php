<?php

namespace App\Support;

use InvalidArgumentException;
use NumberFormatter;

/**
 * Immutable money value object. Stored and reasoned about as **integer cents
 * (EUR)** everywhere; euros appear only at the input/display edge. All
 * arithmetic is integer — a float in money is a bug.
 */
final class Money
{
    public function __construct(public readonly int $cents) {}

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    /**
     * Parse a euros amount from the edge (Filament input, import row) to cents,
     * rounding half-up at the conversion boundary. Accepts "12,50" (Spanish) and
     * "12.50" and numeric types.
     */
    public static function fromEuros(int|float|string $euros): self
    {
        if (is_string($euros)) {
            $euros = str_replace([' ', ','], ['', '.'], trim($euros));
            if ($euros === '' || ! is_numeric($euros)) {
                throw new InvalidArgumentException("Not a valid euro amount: {$euros}");
            }
        }

        return new self((int) round_half_up((float) $euros * 100));
    }

    /**
     * Integer cents from a euro amount a PERSON typed, or null when it is blank or ambiguous (prompt 271, 268's rule).
     *
     * ONE reading ({@see TypedNumber}): digits, optionally one separator and one or two decimals, or the fully written
     * "1.250,00" / "1,250.00" (prompt 306). A lone "1.250" is refused, never read as €1,25 (`fromEuros("1.250")` gives
     * 125 cents). The twin of `Weight`'s grams rule (257).
     * Pure integer arithmetic — no float on the way. Every typed money field at the counter parses through here: the
     * tender, the till's float / count / movements / handover, and the membership fee.
     */
    public static function parseTyped(string $typed): ?int
    {
        // The one typed-number rule (prompt 306 added the fully written "1.250,00"; a lone "1.250" is still refused).
        $canonical = TypedNumber::canonical($typed, maxIntegerDigits: 9);
        if ($canonical === null) {
            return null;
        }
        [$euros, $decimals] = array_pad(explode('.', $canonical), 2, '');

        return (int) $euros * 100 + (int) str_pad($decimals, 2, '0');
    }

    public function euros(): float
    {
        return $this->cents / 100;
    }

    public function add(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function subtract(self $other): self
    {
        return new self($this->cents - $other->cents);
    }

    public function multiply(int $quantity): self
    {
        return new self($this->cents * $quantity);
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }

    /**
     * Display edge only: "1234.56 €" in Spanish, "€1234.56" in English. The currency symbol keeps its language's position
     * (the locale's own currency pattern); the number follows the one rule — a decimal POINT and no grouping (prompt
     * 316, {@see NumberFormat}).
     */
    public function formatted(?string $locale = null): string
    {
        $formatter = new NumberFormatter($locale ?? app()->getLocale(), NumberFormatter::CURRENCY);
        $formatter->setSymbol(NumberFormatter::MONETARY_SEPARATOR_SYMBOL, '.');
        $formatter->setSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL, '.');
        $formatter->setAttribute(NumberFormatter::GROUPING_USED, 0);

        return $formatter->formatCurrency($this->euros(), 'EUR') ?: NumberFormat::decimal($this->euros(), 2).' €';
    }

    public function __toString(): string
    {
        return (string) $this->cents;
    }
}
