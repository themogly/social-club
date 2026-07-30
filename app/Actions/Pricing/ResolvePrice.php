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
    public function forGenetic(Genetic $genetic, Location $location, ?Member $member = null): PriceResult
    {
        [$rate, $rateLabel] = $this->rate($genetic, $location, $member);
        $candidates = $this->applicableDiscounts($genetic, $location, $member);

        return new PriceResult($rate, $rateLabel, $this->chooseDiscount($rate, $candidates));
    }

    /**
     * @return array{0: int, 1: ?string}
     */
    private function rate(Genetic $genetic, Location $location, ?Member $member): array
    {
        $tierId = $member !== null ? $this->activeTierId($member, $location) : null;

        if ($tierId !== null) {
            $tierPrice = GeneticPrice::query()->withoutGlobalScopes()
                ->where('genetic_id', $genetic->id)->where('location_id', $location->id)
                ->where('tier_id', $tierId)->where('active', true)->first();

            if ($tierPrice !== null) {
                return [(int) $tierPrice->price_per_gram_cents, __('Tarifa')];
            }
        }

        $base = GeneticPrice::query()->withoutGlobalScopes()
            ->where('genetic_id', $genetic->id)->where('location_id', $location->id)
            ->whereNull('tier_id')->where('active', true)->first();

        if ($base === null) {
            throw new RuntimeException('No active base price for this genetic at this location.');
        }

        return [(int) $base->price_per_gram_cents, null];
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
        $best = null;
        $bestSave = 0;
        foreach ($candidates as $candidate) {
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
