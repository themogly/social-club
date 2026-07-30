<?php

namespace App\Support;

use App\Enums\DiscountMode;

/**
 * The resolved price for a genetic for a member: the rate (base or tier) plus the
 * single best applicable discount and a human reason ("Terapéutico −20%"). The rate is
 * per gram for a WEIGHT genetic and per unit for a UNIT genetic (`perUnit` says which);
 * `ratePerGramCents` holds it in both cases. `lineFor()` computes a WEIGHT line at a
 * given weight; `lineForUnits()` a UNIT line at a given unit count — the one rounding
 * rule, applied once.
 *
 * @phpstan-type Discount array{mode: DiscountMode, value_bp: ?int, value_cents: ?int, label: string}
 * @phpstan-type Line array{rate_cents: int, subtotal_cents: int, discount_cents: int, total_cents: int, label: ?string}
 */
final class PriceResult
{
    /**
     * @param  Discount|null  $discount
     */
    public function __construct(
        public readonly int $ratePerGramCents,
        public readonly ?string $rateLabel = null,
        public readonly ?array $discount = null,
        public readonly bool $perUnit = false,
    ) {}

    /**
     * A WEIGHT line: subtotal = rate/g × grams. Grams in from integer centigrams.
     *
     * @return Line
     */
    public function lineFor(int $gramsCg): array
    {
        $subtotal = (int) round_half_up($this->ratePerGramCents * $gramsCg / 100);

        return $this->line($subtotal);
    }

    /**
     * A UNIT line: subtotal = rate/unit × units (whole units — no division).
     *
     * @return Line
     */
    public function lineForUnits(int $units): array
    {
        return $this->line($this->ratePerGramCents * $units);
    }

    /**
     * @return Line
     */
    private function line(int $subtotal): array
    {
        $discountCents = $this->discountAmount($subtotal);

        return [
            'rate_cents' => $this->ratePerGramCents,
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discountCents,
            'total_cents' => $subtotal - $discountCents,
            'label' => $this->label(),
        ];
    }

    /** The saving this discount would apply to a subtotal, in cents. */
    public function discountAmount(int $subtotalCents): int
    {
        if ($this->discount === null) {
            return 0;
        }

        if ($this->discount['mode'] === DiscountMode::PERCENT) {
            return (int) round_half_up($subtotalCents * (int) $this->discount['value_bp'] / 10_000);
        }

        return min((int) $this->discount['value_cents'], $subtotalCents);
    }

    /** A straight answer for the counter/receipt: why this price. */
    public function label(): ?string
    {
        $parts = array_filter([
            $this->rateLabel,
            $this->discount !== null ? $this->discountLabel() : null,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private function discountLabel(): string
    {
        $discount = $this->discount;
        $suffix = $discount['mode'] === DiscountMode::PERCENT
            ? '−'.number_format((int) $discount['value_bp'] / 100, 2).'%'
            : '−'.Money::fromCents((int) $discount['value_cents'])->formatted();

        return $discount['label'].' '.$suffix;
    }
}
