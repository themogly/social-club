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
     * Locale-aware display, e.g. "1.234,56 €" in Spanish. Display edge only.
     */
    public function formatted(?string $locale = null): string
    {
        $formatter = new NumberFormatter($locale ?? app()->getLocale(), NumberFormatter::CURRENCY);

        return $formatter->formatCurrency($this->euros(), 'EUR') ?: number_format($this->euros(), 2);
    }

    public function __toString(): string
    {
        return (string) $this->cents;
    }
}
