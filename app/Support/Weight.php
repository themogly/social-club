<?php

namespace App\Support;

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
     * Parse a grams amount from the edge (weight entry, forecast) to centigrams,
     * rounding half-up at the conversion boundary. Accepts "3,5" and "3.5".
     */
    public static function fromGrams(int|float|string $grams): self
    {
        if (is_string($grams)) {
            $grams = str_replace([' ', ','], ['', '.'], trim($grams));
            if ($grams === '' || ! is_numeric($grams)) {
                throw new InvalidArgumentException("Not a valid gram amount: {$grams}");
            }
        }

        return new self((int) round_half_up((float) $grams * 100));
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
