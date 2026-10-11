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
 * Prompt 382 — or charged on the member's PRICE LIST (Local / Personal): `$list` holds that list's rate and 3.5 g price. The
 * standard rate stays `ratePerGramCents`, so a line still records `discount_cents` = standard − charged and the reports keep
 * their lines; only the arithmetic of the charge changes. On a list no discount applies at all (383,
 * {@see ResolvePrice::forBatch()}).
 *
 * @phpstan-type Discount array{mode: DiscountMode, value_bp: ?int, value_cents: ?int, label: string, kind?: ?string}
 * @phpstan-type ListPrice array{rate: int, eighth: ?int, label: string, kind: string}
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
        /** Optional eighth (3.5 g) price for this strain, in cents (prompt 83). Null = no eighth price. */
        public readonly ?int $eighthPriceCents = null,
        /** @var ListPrice|null Prompt 382 — charged at this price list's own rates instead of a discount on the standard. */
        public readonly ?array $list = null,
    ) {}

    /**
     * Prompt 382 — the rate to SHOW beside a strain: the member's list rate when their list priced it, else the standard (a
     * discount is shown as its own label, never folded in).
     */
    public function shownRateCents(): int
    {
        return $this->list['rate'] ?? $this->ratePerGramCents;
    }

    /** The per-gram rate AFTER the chosen discount — used to price the sub-eighth remainder (prompt 83). */
    public function effectiveRatePerGramCents(): int
    {
        if ($this->list !== null) {
            return $this->list['rate'];
        }

        return $this->ratePerGramCents - $this->discountAmount($this->ratePerGramCents);
    }

    /**
     * The eighth price AFTER the chosen discount (prompt 90). The member's discount applies to the eighth
     * price exactly as it applies to the per-gram rate — the SAME chosen discount (discountAmount), never a
     * separately re-derived one — so a discount can never silently vanish on an eighth-priced quantity.
     * Null when the strain has no eighth price.
     */
    public function effectiveEighthPriceCents(): ?int
    {
        if ($this->eighthPriceCents === null) {
            return null;
        }
        if ($this->list !== null) {
            return $this->list['eighth']; // the list's own 3.5 g price (Batch::priceFor), never a discount on the standard one
        }

        return $this->eighthPriceCents - $this->discountAmount($this->eighthPriceCents);
    }

    /**
     * A WEIGHT line: subtotal = rate/g × grams. Grams in from integer centigrams.
     *
     * @return Line
     */
    public function lineFor(int $gramsCg): array
    {
        $subtotal = (int) round_half_up($this->ratePerGramCents * $gramsCg / 100);

        return $this->line($subtotal, $this->list === null ? null : (int) round_half_up($this->list['rate'] * $gramsCg / 100));
    }

    /**
     * A UNIT line: subtotal = rate/unit × units (whole units — no division).
     *
     * @return Line
     */
    public function lineForUnits(int $units): array
    {
        return $this->line($this->ratePerGramCents * $units, $this->list === null ? null : $this->list['rate'] * $units);
    }

    /**
     * @return Line
     */
    private function line(int $subtotal, ?int $listTotal = null): array
    {
        // On a price list the member pays the list's own total; the difference from the standard is recorded as the discount.
        $discountCents = $listTotal !== null ? max(0, $subtotal - $listTotal) : $this->discountAmount($subtotal);

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

    /** Prompt 350 — the kind of the discount applied (a DiscountKind value, TIER, or null). */
    public function discountKind(): ?string
    {
        if ($this->list !== null) {
            return $this->list['kind'];
        }

        return $this->discount['kind'] ?? null;
    }

    /** A straight answer for the counter/receipt: why this price. */
    public function label(): ?string
    {
        $parts = array_filter([
            $this->rateLabel,
            $this->list !== null ? $this->list['label'] : ($this->discount !== null ? $this->discountLabel() : null),
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private function discountLabel(): string
    {
        $discount = $this->discount;
        $suffix = $discount['mode'] === DiscountMode::PERCENT
            ? '−'.Percent::formatted((int) $discount['value_bp'] / 100)
            : '−'.Money::fromCents((int) $discount['value_cents'])->formatted();

        return $discount['label'].' '.$suffix;
    }
}
