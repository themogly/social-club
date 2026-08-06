<?php

namespace App\Actions\Pricing;

use App\Enums\DiscountAppliesTo;
use App\Enums\DiscountKind;
use App\Enums\DiscountMode;
use App\Enums\MembershipStatus;
use App\Models\Discount;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Support\PriceResult;
use App\Support\Settings;
use RuntimeException;

/**
 * THE one price resolver — POS, PWA menu, reports and receipts all call this; no
 * second copy of the arithmetic. Resolution order: **tier price → best single
 * applicable discount → per-member custom (if better)**. Discounts do NOT stack
 * unless the `discounts_stack` setting says so. Therapeutic members get the
 * therapeutic discount automatically. The result is frozen into the dispensation
 * snapshot at commit, so a later price change never rewrites history.
 *
 * @phpstan-type DiscountShape array{mode: DiscountMode, value_bp: ?int, value_cents: ?int, label: string}
 */
class ResolvePrice
{
    /** One eighth = 3.5 g = 350 cg. Never a float comparison. */
    public const EIGHTH_CG = 350;

    public function forGenetic(Genetic $genetic, Location $location, ?Member $member = null): PriceResult
    {
        [$rate, $rateLabel, $eighth] = $this->rate($genetic, $location, $member);
        $candidates = $this->applicableDiscounts($genetic, $location, $member);

        return new PriceResult($rate, $rateLabel, $this->chooseDiscount($rate, $candidates), $genetic->isUnitType(), $eighth);
    }

    /**
     * The resolved rate in cents — SAME tier resolution for both, only the column
     * differs: per gram for a WEIGHT genetic, per unit for a UNIT genetic. Also returns the strain's
     * eighth price (WEIGHT only) from the SAME resolved row, for the basket-level eighth break (prompt 83).
     *
     * @return array{0: int, 1: ?string, 2: ?int}
     */
    private function rate(Genetic $genetic, Location $location, ?Member $member): array
    {
        $isUnit = $genetic->isUnitType();
        $column = $isUnit ? 'price_per_unit_cents' : 'price_per_gram_cents';
        $tierId = $member !== null ? $this->activeTierId($member, $location) : null;

        if ($tierId !== null) {
            $tierPrice = GeneticPrice::query()->withoutGlobalScopes()
                ->where('genetic_id', $genetic->id)->where('location_id', $location->id)
                ->where('tier_id', $tierId)->where('active', true)->first();

            if ($tierPrice !== null) {
                return [(int) $tierPrice->{$column}, __('Tarifa'), $isUnit ? null : $tierPrice->price_per_eighth_cents];
            }
        }

        $base = GeneticPrice::query()->withoutGlobalScopes()
            ->where('genetic_id', $genetic->id)->where('location_id', $location->id)
            ->whereNull('tier_id')->where('active', true)->first();

        if ($base === null) {
            throw new RuntimeException('No active base price for this genetic at this location.');
        }

        return [(int) $base->{$column}, null, $isUnit ? null : $base->price_per_eighth_cents];
    }

    /**
     * Apply eighth (3.5 g) quantity breaks across a WHOLE weight basket (prompt 83) — "calculate it in the
     * background". Lines are GROUPED by their (identical, non-null) EFFECTIVE eighth price (i.e. after the
     * member's discount — prompt 90); a group reaching >= 350 cg is charged floor(grams / 350) eighths at
     * that price PLUS the sub-eighth remainder per gram, split across the group's lines proportional to
     * grams by largest-remainder so the per-line totals sum EXACTLY to the group charge — no lost or gained
     * cent, at every multiple.
     *
     * The break is applied ONLY when it is genuinely cheaper: the group charge is FLOORED at the group's own
     * per-gram total, so an eighth price set above the per-gram total (a typo, or a per-gram price later
     * reduced) can NEVER increase what a member pays (prompt 90 — the acceptance property). When the break is
     * not cheaper the lines keep their per-gram totals unchanged. Because the eighth price passed in is
     * already discounted, the discount applies ON TOP of the eighth, and the floor is discounted-vs-discounted.
     *
     * `rate_cents` and `eighth_price` are the EFFECTIVE (post-discount) figures; `per_gram_total` is the
     * line's post-discount per-gram charge.
     *
     * @param  list<array{grams_cg: int, rate_cents: int, per_gram_total: int, eighth_price: ?int}>  $lines
     * @return list<array{total_cents: int, eighth_applied: bool}> in the same order
     */
    public function applyEighthBreaks(array $lines): array
    {
        $result = array_map(fn (array $l): array => ['total_cents' => $l['per_gram_total'], 'eighth_applied' => false], $lines);

        // Group line indices by (effective) eighth price (only lines that HAVE one).
        $groups = [];
        foreach ($lines as $i => $line) {
            if ($line['eighth_price'] !== null && $line['eighth_price'] > 0) {
                $groups[(int) $line['eighth_price']][] = $i;
            }
        }

        foreach ($groups as $eighthPrice => $indices) {
            $totalCg = array_sum(array_map(fn (int $i): int => $lines[$i]['grams_cg'], $indices));
            $eighths = intdiv($totalCg, self::EIGHTH_CG);

            if ($eighths === 0) {
                continue; // below one eighth → stays per-gram (near-miss like 3.4 g)
            }

            // The group charge, computed ONCE: N eighths + the sub-eighth remainder at the group's LOWEST
            // effective rate (member-favourable). Discount is already baked into $eighthPrice and the rates.
            $remainderCg = $totalCg - $eighths * self::EIGHTH_CG;
            $minRate = min(array_map(fn (int $i): int => $lines[$i]['rate_cents'], $indices));
            $eighthCharge = $eighths * (int) $eighthPrice + (int) round_half_up($minRate * $remainderCg / 100);

            // FLOOR (prompt 90): never charge more than the group would cost per gram. If the "break" is not
            // strictly cheaper, keep the per-gram totals (already in $result) — a member is never charged
            // more because an eighth price exists.
            $groupPerGramTotal = array_sum(array_map(fn (int $i): int => $lines[$i]['per_gram_total'], $indices));
            if ($eighthCharge >= $groupPerGramTotal) {
                continue;
            }

            $shares = $this->distribute($eighthCharge, array_map(fn (int $i): int => $lines[$i]['grams_cg'], $indices));
            foreach ($indices as $pos => $i) {
                $result[$i] = ['total_cents' => $shares[$pos], 'eighth_applied' => true];
            }
        }

        return $result;
    }

    /**
     * Split $total across the given integer weights with NO lost/gained cent (largest-remainder).
     *
     * @param  list<int>  $weights
     * @return list<int>
     */
    private function distribute(int $total, array $weights): array
    {
        $sumW = array_sum($weights);
        if ($sumW <= 0) {
            return array_fill(0, count($weights), 0);
        }

        $floors = [];
        $fracs = [];
        foreach ($weights as $j => $w) {
            $numerator = $total * $w;
            $floors[$j] = intdiv($numerator, $sumW);
            $fracs[$j] = $numerator % $sumW;
        }

        $remaining = $total - array_sum($floors);
        arsort($fracs); // largest fractional part first (stable enough — ties break on earlier index)
        foreach (array_keys($fracs) as $j) {
            if ($remaining <= 0) {
                break;
            }
            $floors[$j]++;
            $remaining--;
        }

        ksort($floors);

        return array_values($floors);
    }

    private function activeTierId(Member $member, Location $location): ?string
    {
        return $member->memberships()->withoutGlobalScopes()
            ->where('location_id', $location->id)
            ->where('status', MembershipStatus::ACTIVE->value)
            ->latest('id')->value('tier_id');
    }

    /**
     * @return list<DiscountShape>
     */
    private function applicableDiscounts(Genetic $genetic, Location $location, ?Member $member): array
    {
        if ($member === null) {
            return [];
        }

        $candidates = [];

        // Therapeutic members get the therapeutic discount automatically.
        if ($member->is_therapeutic) {
            $therapeutic = Discount::query()->withoutGlobalScopes()
                ->where('organisation_id', $member->organisation_id)
                ->where('kind', DiscountKind::THERAPEUTIC->value)
                ->where('active', true)
                ->whereHas('locations', fn ($q) => $q->whereKey($location->id))
                ->get()
                ->filter(fn (Discount $d) => $this->appliesToGenetic($d, $genetic));

            foreach ($therapeutic as $discount) {
                $candidates[] = $this->fromDiscount($discount);
            }
        }

        // Assigned discounts (standard or per-member custom), not expired.
        foreach ($member->memberDiscounts()->with('discount')->get() as $memberDiscount) {
            if ($memberDiscount->expires_at !== null && $memberDiscount->expires_at->isPast()) {
                continue;
            }

            if ($memberDiscount->discount_id !== null) {
                $discount = $memberDiscount->discount;
                if ($discount !== null && $discount->active && $this->appliesToGenetic($discount, $genetic)) {
                    $candidates[] = $this->fromDiscount($discount);
                }
            } elseif ($memberDiscount->mode !== null) {
                $candidates[] = [
                    'mode' => $memberDiscount->mode,
                    'value_bp' => $memberDiscount->value_bp,
                    'value_cents' => $memberDiscount->value_cents, // plain int cents on MemberDiscount
                    'label' => __('Personalizado'),
                ];
            }
        }

        return $candidates;
    }

    private function appliesToGenetic(Discount $discount, Genetic $genetic): bool
    {
        if (! in_array($discount->applies_to, [DiscountAppliesTo::GENETIC, DiscountAppliesTo::BOTH], true)) {
            return false;
        }

        return $discount->category_id === null || $discount->category_id === $genetic->category_id;
    }

    /**
     * @return DiscountShape
     */
    private function fromDiscount(Discount $discount): array
    {
        return [
            'mode' => $discount->mode,
            'value_bp' => $discount->value_bp,
            'value_cents' => $discount->value_cents?->cents,
            'label' => $discount->name,
        ];
    }

    /**
     * @param  list<DiscountShape>  $candidates
     * @return DiscountShape|null
     */
    private function chooseDiscount(int $rate, array $candidates): ?array
    {
        if ($candidates === []) {
            return null;
        }

        // Stacking mode: combine every percentage discount into one effective percent.
        if (Settings::get('discounts_stack', false)) {
            $totalBp = array_sum(array_map(
                fn (array $c) => $c['mode'] === DiscountMode::PERCENT ? (int) $c['value_bp'] : 0,
                $candidates,
            ));
            if ($totalBp > 0) {
                return ['mode' => DiscountMode::PERCENT, 'value_bp' => min($totalBp, 10_000), 'value_cents' => null, 'label' => __('Descuentos')];
            }
        }

        // Default: the single discount that saves the member the most on one gram.
        //
        // A FIXED amount cannot be ranked against a PERCENT here (prompt 168). Candidates are compared
        // on ONE GRAM's rate, but PriceResult::discountAmount() applies the winner to the WHOLE
        // subtotal — so a percentage scales with the order and a fixed amount does not, and the
        // comparison systematically over-values the fixed one. Measured against the real classes:
        // rate €10/g, 10 g, subtotal €100, candidates 10% vs €3 fixed → the €3 won and the member was
        // charged €7.00 more than the best discount available to them.
        //
        // The quantity is not known at price resolution, so there is no basis on which the two CAN be
        // compared here. A fixed amount therefore never COMPETES: it applies only when it is the sole
        // candidate, which keeps a legacy row pricing (nothing can author one since prompt 168) without
        // letting it beat a better percentage.
        $comparable = array_values(array_filter($candidates, fn (array $c): bool => $c['mode'] === DiscountMode::PERCENT));
        if ($comparable === []) {
            $comparable = $candidates;
        }

        $best = null;
        $bestSave = 0;
        foreach ($comparable as $candidate) {
            $save = $candidate['mode'] === DiscountMode::PERCENT
                ? (int) round_half_up($rate * (int) $candidate['value_bp'] / 10_000)
                : min((int) $candidate['value_cents'], $rate);

            if ($save > $bestSave) {
                $bestSave = $save;
                $best = $candidate;
            }
        }

        return $best;
    }
}
