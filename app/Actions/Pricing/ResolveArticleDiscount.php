<?php

namespace App\Actions\Pricing;

use App\Enums\DiscountAppliesTo;
use App\Enums\DiscountKind;
use App\Enums\DiscountMode;
use App\Models\Discount;
use App\Models\Location;
use App\Models\Member;

/**
 * THE single resolver for a member's bar/merch (article) discount (prompt 55) — the counterpart to
 * ResolvePrice for genetics. The bar had no discount path even though `DiscountAppliesTo::ARTICLE`
 * existed from the start; this consumes it. Called by BOTH the bar POS (display) and CommitOrder
 * (commit), so the shown total and the charged total can never desync.
 *
 * Deliberate shape (recorded in DECISIONS): reuse the EXISTING member Discount system, PERCENTAGE
 * discounts only, that explicitly apply to ARTICLE or BOTH — a fixed-amount or a custom per-member
 * cannabis discount does NOT leak onto beer. Best single discount, never stacked. Guests (no member)
 * get no discount.
 */
class ResolveArticleDiscount
{
    /** The best applicable article discount for this member at this location, in basis points (0 = none). */
    public function bpFor(Member $member, Location $location): int
    {
        $best = 0;

        // Therapeutic members get a THERAPEUTIC-kind discount automatically — but only if it applies to
        // articles (a genetics-only therapeutic discount does not touch the bar).
        if ($member->is_therapeutic) {
            $therapeutic = Discount::query()->withoutGlobalScopes()
                ->where('organisation_id', $member->organisation_id)
                ->where('kind', DiscountKind::THERAPEUTIC->value)
                ->where('active', true)
                ->where('mode', DiscountMode::PERCENT->value)
                ->whereIn('applies_to', [DiscountAppliesTo::ARTICLE->value, DiscountAppliesTo::BOTH->value])
                ->whereHas('locations', fn ($q) => $q->whereKey($location->id))
                ->get();

            foreach ($therapeutic as $discount) {
                $best = max($best, (int) $discount->value_bp);
            }
        }

        // Assigned standard discounts (not the custom per-member overrides, which are cannabis-side).
        foreach ($member->memberDiscounts()->with('discount')->get() as $memberDiscount) {
            if ($memberDiscount->expires_at !== null && $memberDiscount->expires_at->isPast()) {
                continue;
            }

            $discount = $memberDiscount->discount;
            if ($discount !== null
                && $discount->active
                && $discount->mode === DiscountMode::PERCENT
                && in_array($discount->applies_to, [DiscountAppliesTo::ARTICLE, DiscountAppliesTo::BOTH], true)
                && $discount->locations()->whereKey($location->id)->exists()) {
                $best = max($best, (int) $discount->value_bp);
            }
        }

        return $best;
    }

    /** The discount in cents on a gross line/subtotal, for the resolved basis points. */
    public function discountCents(int $grossCents, int $bp): int
    {
        return $bp > 0 ? (int) round_half_up($grossCents * $bp / 10_000) : 0;
    }
}
