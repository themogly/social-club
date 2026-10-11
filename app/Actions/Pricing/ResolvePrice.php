<?php

namespace App\Actions\Pricing;

use App\Actions\Stock\SelectBatch;
use App\Enums\BatchStatus;
use App\Enums\DiscountAppliesTo;
use App\Enums\DiscountKind;
use App\Enums\DiscountMode;
use App\Enums\MembershipStatus;
use App\Enums\PriceList;
use App\Models\Batch;
use App\Models\Discount;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberDiscount;
use App\Models\MembershipTier;
use App\Support\ChargeRounding;
use App\Support\PriceResult;
use App\Support\Settings;
use App\Support\StaffDiscount;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * THE one price resolver — POS, PWA menu, reports and receipts all call this; no
 * second copy of the arithmetic.
 *
 * **The price is the BATCH's (prompt 278, Ben's 271).** Two harvests of one strain differ, so the rate comes from the
 * batch the grams are drawn from (`forBatch`); a strain's "price" at a sede is the price of the batch that will be
 * dispensed next there (`forGenetic` → `displayBatch`, FEFO). Prompt 382: a membership tier picks the batch's price LIST
 * (Estándar / Local / Personal, `Batch::priceFor()`); prompt 383: on Local / Personal the list price is final and no %
 * discount applies — discounts are for the Estándar list. The strain's sede price in
 * `genetic_prices` is only the FALLBACK for a batch without its own price (batches received before 278 that had no base
 * price to backfill from, and fixtures) — and on that legacy path tier price rows still apply as before.
 *
 * Legacy resolution order: **tier price → best single
 * applicable discount → per-member custom (if better)**. Discounts do NOT stack
 * unless the `discounts_stack` setting says so. Therapeutic members get the
 * therapeutic discount automatically. The result is frozen into the dispensation
 * snapshot at commit, so a later price change never rewrites history.
 *
 * @phpstan-type DiscountShape array{mode: DiscountMode, value_bp: ?int, value_cents: ?int, label: string, kind?: ?string}
 */
class ResolvePrice
{
    /** One eighth = 3.5 g = 350 cg. Never a float comparison. */
    public const EIGHTH_CG = 350;

    /**
     * Per-INSTANCE memo of the member-side lookups (prompt 273). The dispensary resolves a price for every card with one
     * resolver per render; the member's tier and discounts are the same for every card, and re-reading them per card
     * was most of the grid's 119 queries. A new instance (every render, every commit) reads them afresh.
     *
     * @var array<string, mixed>
     */
    private array $memo = [];

    /** What this strain costs at this sede now: the price of the batch to be dispensed next (278), else the legacy row. */
    public function forGenetic(Genetic $genetic, Location $location, ?Member $member = null): PriceResult
    {
        $batch = $this->displayBatch($genetic, $location);

        return $batch !== null ? $this->forBatch($batch, $genetic, $location, $member) : $this->legacy($genetic, $location, $member);
    }

    /** The price of grams drawn from THIS batch (278). A batch without its own price falls back to the strain's sede price. */
    public function forBatch(Batch $batch, Genetic $genetic, Location $location, ?Member $member = null): PriceResult
    {
        if (! $batch->hasOwnPrice()) {
            return $this->legacy($genetic, $location, $member);
        }

        $isUnit = $genetic->isUnitType();
        $rate = (int) ($isUnit ? $batch->price_per_unit_cents : $batch->price_per_gram_cents);
        $eighth = $isUnit ? null : $batch->price_per_eighth_cents;

        // Prompt 383 (Ben, 11 Oct: "if a price is attached it overrides it") — a member on a Local / Personal list pays that
        // list's price, set or defaulted, and NO % discount applies (therapeutic, concession, custom, an old staff/local %).
        // 382 had the lower of the two win. Discounts are for the Estándar list only, best single one as before.
        $list = $this->priceListFor($member, $location);
        if ($list !== PriceList::STANDARD) {
            return new PriceResult($rate, null, null, $isUnit, $eighth, [
                'rate' => (int) $batch->priceFor($list, $isUnit ? 'unit' : 'gram')['cents'], // the standard exists (hasOwnPrice)
                'eighth' => $isUnit ? null : $batch->priceFor($list, 'eighth')['cents'],
                'label' => $list->label(),
                'kind' => (string) $list->discountKind(),
            ]);
        }

        return new PriceResult($rate, null, $this->chooseDiscount($rate, $this->applicableDiscounts($genetic, $location, $member)), $isUnit, $eighth);
    }

    /** Prompt 382 — the price list this member pays at this sede: their active tier's, else Estándar. */
    public function priceListFor(?Member $member, Location $location): PriceList
    {
        return $member === null ? PriceList::STANDARD : ($this->activeTier($member, $location)->price_list ?? PriceList::STANDARD);
    }

    /**
     * Price a line drawn from these batch PARTS (278, owner decision 2): each part at ITS OWN batch's price, the line
     * the sum — honest and traceable. The counter's preview and `CommitDispensation` both call this, so the total shown
     * is the total charged. `eighth_price` is set only when every part shares one (the basket-wide break then applies).
     *
     * Prompt 355 — `$chargedCg` (weight lines): the grams the member PAYS for, the line's weighed total rounded to the half
     * gram. The parts keep their WEIGHED quantities (stock moves by them); each is PRICED on its share of the charged
     * grams ({@see ChargeRounding::overParts()}: the difference applied from the last part back, never below zero).
     *
     * @param  list<array{batch: Batch, qty: int}>  $parts  qty in centigrams (weight) or units
     * @return array{parts: list<array{batch: Batch, qty: int, charged_qty: int, rate_cents: int, total_cents: int, discount_cents: int, list_rate_cents: ?int, list_label: ?string}>, total_cents: int, discount_cents: int, rate_cents: int, effective_rate_cents: int, eighth_price: ?int, label: ?string, mixed: bool, discount_kind: ?string, list: array{rate_cents: int, label: string}|null, standard_rate_cents: int, standard_eighth_price: ?int}
     */
    public function priceParts(Genetic $genetic, Location $location, ?Member $member, array $parts, bool $isUnit, ?int $chargedCg = null): array
    {
        $out = [];
        $eighths = [];
        $standardEighths = [];
        $firstPrice = null;
        $firstLabel = null;
        $charged = ! $isUnit && $chargedCg !== null
            ? ChargeRounding::overParts(array_map(fn (array $p): int => (int) $p['qty'], $parts), $chargedCg)
            : array_map(fn (array $p): int => (int) $p['qty'], $parts);

        foreach ($parts as $j => $part) {
            $price = $this->forBatch($part['batch'], $genetic, $location, $member);
            $line = $isUnit ? $price->lineForUnits($part['qty']) : $price->lineFor($charged[$j]);
            $firstPrice ??= $price;
            $firstLabel ??= $line['label'];
            $eighths[] = $isUnit ? null : $price->effectiveEighthPriceCents();
            $standardEighths[] = $isUnit ? null : $price->eighthPriceCents; // 383 — what a standard member's break would be
            $out[] = ['batch' => $part['batch'], 'qty' => $part['qty'], 'charged_qty' => $charged[$j], 'rate_cents' => $line['rate_cents'], 'total_cents' => $line['total_cents'], 'discount_cents' => $line['discount_cents'],
                'list_rate_cents' => $price->list['rate'] ?? null, 'list_label' => $price->list['label'] ?? null]; // prompt 382
        }

        $rates = array_unique(array_column($out, 'rate_cents'));

        return [
            'parts' => $out,
            'total_cents' => (int) array_sum(array_column($out, 'total_cents')),
            'discount_cents' => (int) array_sum(array_column($out, 'discount_cents')),
            'rate_cents' => $out[0]['rate_cents'] ?? 0,
            'effective_rate_cents' => $firstPrice?->effectiveRatePerGramCents() ?? 0,
            'eighth_price' => count(array_unique($eighths, SORT_REGULAR)) === 1 ? ($eighths[0] ?? null) : null,
            'label' => $firstLabel,
            'mixed' => count($rates) > 1,
            // Prompt 350 — the applied discount's kind (null when none applied), for the rounding scope.
            'discount_kind' => (int) array_sum(array_column($out, 'discount_cents')) > 0 ? $firstPrice?->discountKind() : null,
            // Prompt 382 — charged on the member's price list: the basket shows that rate and the list («8.80 €/g · Local»).
            'list' => $firstPrice?->list !== null ? ['rate_cents' => $firstPrice->list['rate'], 'label' => $firstPrice->list['label']] : null,
            // Prompt 383 — the same line for a standard member, for measuring a list line's discount (standardLine()).
            'standard_rate_cents' => $out === [] ? 0 : min(array_column($out, 'rate_cents')),
            'standard_eighth_price' => count(array_unique($standardEighths, SORT_REGULAR)) === 1 ? ($standardEighths[0] ?? null) : null,
        ];
    }

    /**
     * The batch whose price (and photo) a strain shows at a sede (278): the one to be dispensed next (FEFO), else the
     * most recently received priced batch still OPEN there (so an empty-but-listed strain still shows its price).
     */
    public function displayBatch(Genetic $genetic, Location $location): ?Batch
    {
        $key = 'display|'.$genetic->id.'|'.$location->id;

        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = (new SelectBatch)->fefo($genetic, $location)
                ?? $this->latestPricedOpen($genetic->id, $location->id);
        }

        /** @var ?Batch */
        return $this->memo[$key];
    }

    /**
     * Prompt 376 — can the counter put a price on this strain at this sede? The batch it would draw from carries its own price
     * above 0, or (that batch having none) the sede has an active base price above 0: exactly what `forBatch()` / `legacy()`
     * would charge — this IS that rule, so the *Sedes* badge and the strains list can never disagree with the counter.
     * Cheap over a list after `preloadDisplayBatches()` and with each strain's `prices` loaded.
     */
    public function canPrice(Genetic $genetic, Location $location): bool
    {
        $column = $genetic->isUnitType() ? 'price_per_unit_cents' : 'price_per_gram_cents';
        $batch = $this->displayBatch($genetic, $location);
        if ($batch !== null && $batch->hasOwnPrice()) {
            return (int) $batch->getRawOriginal($column) > 0;
        }

        return (int) ($this->priceRow($genetic, $location, null)?->getRawOriginal($column) ?? 0) > 0;
    }

    /**
     * Resolve `displayBatch()` for a whole list in two queries (273's rule — the counter grid must not query per card).
     *
     * @param  iterable<Genetic>  $genetics
     */
    public function preloadDisplayBatches(iterable $genetics, Location $location): void
    {
        $ids = [];
        $byId = [];
        foreach ($genetics as $genetic) {
            $ids[] = $genetic->id;
            $byId[$genetic->id] = $genetic;
        }
        if ($ids === []) {
            return;
        }

        $dispensable = Batch::query()->withoutGlobalScopes()->whereIn('genetic_id', $ids)->where('location_id', $location->id)
            ->fefo($location->id)->get()->groupBy('genetic_id');
        $priced = Batch::query()->withoutGlobalScopes()->whereIn('genetic_id', $ids)->where('location_id', $location->id)
            ->where('status', BatchStatus::OPEN->value)
            ->where(fn ($q) => $q->whereNotNull('price_per_gram_cents')->orWhereNotNull('price_per_unit_cents'))
            ->orderByDesc('acquired_or_harvested_on')->orderByDesc('id')->get()->groupBy('genetic_id');

        foreach ($ids as $id) {
            $batch = $dispensable->get($id)?->first() ?? $priced->get($id)?->first();
            // The strain rides along, so asking the batch its kind (weight/unit) is not a query per card.
            $batch?->setRelation('genetic', $byId[$id]);
            $this->memo['display|'.$id.'|'.$location->id] = $batch;
        }
    }

    /**
     * The photo a strain shows at a sede (278): the next batch's own (or its parent's), else the strain's, else null —
     * the view draws a neutral placeholder for null, never a broken image.
     */
    public function photoUrl(Genetic $genetic, Location $location): ?string
    {
        $path = $this->displayBatch($genetic, $location)?->displayImages()[0]
            ?? (array_values(array_filter((array) ($genetic->images ?? []), 'is_string'))[0] ?? null);

        return $path !== null ? Storage::disk('public')->url($path) : null;
    }

    private function latestPricedOpen(string $geneticId, string $locationId): ?Batch
    {
        return Batch::query()->withoutGlobalScopes()
            ->where('genetic_id', $geneticId)->where('location_id', $locationId)
            ->where('status', BatchStatus::OPEN->value)
            ->where(fn ($q) => $q->whereNotNull('price_per_gram_cents')->orWhereNotNull('price_per_unit_cents'))
            ->orderByDesc('acquired_or_harvested_on')->orderByDesc('id')->first();
    }

    /** The pre-278 strain-level price (`genetic_prices`): tier row → base row. The fallback for an unpriced batch. */
    private function legacy(Genetic $genetic, Location $location, ?Member $member): PriceResult
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
            $tierPrice = $this->priceRow($genetic, $location, $tierId);

            if ($tierPrice !== null) {
                return [(int) $tierPrice->{$column}, __('Tarifa'), $isUnit ? null : $tierPrice->price_per_eighth_cents];
            }
        }

        $base = $this->priceRow($genetic, $location, null);

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
     * Prompt 383 — one priced line as a STANDARD member would pay it, the input `measuredDiscounts()` breaks into eighths:
     * the standard per-gram total (what was charged + its per-gram discount) and the standard 3.5 g price. Unit lines have
     * no eighth.
     *
     * @param  array{total_cents: int, discount_cents: int, standard_rate_cents: int, standard_eighth_price: ?int}  $priced  a priceParts() result
     * @return array{grams_cg: int, rate_cents: int, per_gram_total: int, eighth_price: ?int}
     */
    public function standardLine(array $priced, int $chargedCg, bool $isUnit): array
    {
        return [
            'grams_cg' => $chargedCg,
            'rate_cents' => $isUnit ? 0 : $priced['standard_rate_cents'],
            'per_gram_total' => $priced['total_cents'] + $priced['discount_cents'],
            'eighth_price' => $isUnit ? null : $priced['standard_eighth_price'],
        ];
    }

    /**
     * Prompt 383 — the discount each line records. A list-priced line's is what the same basket would cost a standard member
     * — standard rates WITH the standard 3.5 g break, grouped basket-wide exactly as the real one (`applyEighthBreaks`) —
     * minus what was charged, never negative. Measured per gram (382) it overstated a 3.5 g sale: 3.5 × (10.00 − 8.00) = 7.00
     * against a real 30.00 − 25.00 = 5.00. Other lines keep their own discount. Before 350's whole-euro rounding.
     *
     * @param  list<array{list: bool, discount_cents: int, standard: array{grams_cg: int, rate_cents: int, per_gram_total: int, eighth_price: ?int}}>  $lines
     * @param  list<array{total_cents: int, eighth_applied: bool}>  $charged  applyEighthBreaks() on the real lines, same order
     * @return list<int>
     */
    public function measuredDiscounts(array $lines, array $charged): array
    {
        $standard = $this->applyEighthBreaks(array_column($lines, 'standard'));

        return array_map(fn (array $line, int $i): int => $line['list'] ? max(0, $standard[$i]['total_cents'] - $charged[$i]['total_cents']) : $line['discount_cents'],
            $lines, array_keys($lines));
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

    /**
     * The active price row for this variety, sede and tarifa (null = base) — newest first, so even a legacy duplicate
     * resolves deterministically (271). Reads the caller's eager-loaded `prices` when present (the dispensary grid loads
     * them for the sede), else one query.
     */
    private function priceRow(Genetic $genetic, Location $location, ?string $tierId): ?GeneticPrice
    {
        if ($genetic->relationLoaded('prices')) {
            return $genetic->prices
                ->filter(fn (GeneticPrice $p): bool => $p->location_id === $location->id && $p->tier_id === $tierId && $p->active)
                ->sortByDesc(fn (GeneticPrice $p): string => ($p->updated_at?->format('YmdHisu') ?? '').'|'.$p->id)
                ->first();
        }

        return GeneticPrice::query()->withoutGlobalScopes()
            ->where('genetic_id', $genetic->id)->where('location_id', $location->id)
            ->when($tierId === null, fn ($q) => $q->whereNull('tier_id'), fn ($q) => $q->where('tier_id', $tierId))
            ->where('active', true)
            ->orderByDesc('updated_at')->orderByDesc('id')->first();
    }

    private function activeTier(Member $member, Location $location): ?MembershipTier
    {
        $id = $this->activeTierId($member, $location);
        if ($id === null) {
            return null;
        }

        $key = 'tier-model|'.$id;
        $this->memo[$key] ??= MembershipTier::query()->withoutGlobalScopes()->find($id);

        /** @var ?MembershipTier */
        return $this->memo[$key];
    }

    private function activeTierId(Member $member, Location $location): ?string
    {
        $key = 'tier|'.$member->id.'|'.$location->id;

        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $member->memberships()->withoutGlobalScopes()
                ->where('location_id', $location->id)
                ->where('status', MembershipStatus::ACTIVE->value)
                ->latest('id')->value('tier_id');
        }

        /** @var ?string */
        return $this->memo[$key];
    }

    /**
     * @return list<DiscountShape>
     */
    private function applicableDiscounts(Genetic $genetic, Location $location, ?Member $member): array
    {
        if ($member === null) {
            return [];
        }

        // Prompt 382 — a tier's `discount_bp` no longer applies to batch prices (278's owner decision 1a): its price list does.
        $candidates = [];

        // Therapeutic members get the therapeutic discount automatically.
        if ($member->is_therapeutic) {
            $key = 'therapeutic|'.$member->organisation_id.'|'.$location->id;
            $this->memo[$key] ??= Discount::query()->withoutGlobalScopes()
                ->where('organisation_id', $member->organisation_id)
                ->where('kind', DiscountKind::THERAPEUTIC->value)
                ->where('active', true)
                ->whereHas('locations', fn ($q) => $q->whereKey($location->id))
                ->get();
            /** @var Collection<int, Discount> $all */
            $all = $this->memo[$key];
            $therapeutic = $all->filter(fn (Discount $d) => $this->appliesToGenetic($d, $genetic));

            foreach ($therapeutic as $discount) {
                $candidates[] = $this->fromDiscount($discount);
            }
        }

        // Assigned discounts (standard or per-member custom), not expired.
        $key = 'assigned|'.$member->id;
        $this->memo[$key] ??= $member->memberDiscounts()->with('discount')->get();
        /** @var Collection<int, MemberDiscount> $assigned */
        $assigned = $this->memo[$key];

        foreach ($assigned as $memberDiscount) {
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
                    'kind' => DiscountKind::CUSTOM->value,
                ];
            }
        }

        // Prompt 347 — the club's staff discount, by itself, for a member linked to an active staff account: one more
        // candidate, exactly like an assigned standard discount (best single wins unless stacking is on).
        $key = 'staff|'.$member->id;
        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = StaffDiscount::for($member);
        }
        $staff = $this->memo[$key];
        if ($staff instanceof Discount && $this->appliesToGenetic($staff, $genetic)) {
            $candidates[] = $this->fromDiscount($staff);
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
            // Prompt 350 — which kind of discount it is, so «round only the Local discount» can tell.
            'kind' => $discount->kind->value,
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
                // Prompt 350 — a stacked total counts as LOCAL when a Local discount is in it.
                $kinds = array_column($candidates, 'kind');

                return ['mode' => DiscountMode::PERCENT, 'value_bp' => min($totalBp, 10_000), 'value_cents' => null, 'label' => __('Descuentos'),
                    'kind' => in_array(DiscountKind::LOCAL->value, $kinds, true) ? DiscountKind::LOCAL->value : ($kinds[0] ?? null)];
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
