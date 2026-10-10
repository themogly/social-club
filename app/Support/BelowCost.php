<?php

namespace App\Support;

use App\Enums\PriceList;
use App\Models\Batch;

/**
 * Prompt 295 (Shane's note 5) — is a sale price below what the stock cost? One rule, read by every place a price meets
 * a cost: *Crear lote* (the add-strain wizard that also asked went in 320), *Añadir stock* and a batch's audited *Precio* action.
 *
 * Compared per unit of sale, in integer cents and centigrams (never a float product):
 *   · by weight — the price per gram against the cost per gram;
 *   · per unit — the price per unit against the cost per gram × the genetic's grams per unit;
 *   · the eighth, when set — the price per 3,5 g against the cost per gram × 3,5.
 * A missing or zero cost is nothing to compare, and a price EQUAL to cost is not below it.
 *
 * A warning, never a block: a club may knowingly sell at a loss to clear old stock. The price is saved as entered, and
 * its existing audit entry says `below_cost: true`.
 */
final class BelowCost
{
    /**
     * @return list<array{field: string, price_cents: int, cost_cents: int, line: string}>
     */
    public static function offences(?int $costPerGramCents, ?int $perGramCents = null, ?int $perUnitCents = null, ?int $perEighthCents = null, ?int $gramsPerUnitCg = null): array
    {
        $cost = (int) $costPerGramCents;
        if ($cost <= 0) {
            return [];
        }

        $found = [];

        // cost × cg / 100 is the cost of that weight in cents; compared as price × 100 < cost × cg, exactly.
        $check = function (string $field, ?int $price, int $cg, string $priceLabel, string $costLabel) use ($cost, &$found): void {
            if ($price === null || $cg <= 0 || $price * 100 >= $cost * $cg) {
                return;
            }
            $costCents = (int) round_half_up($cost * $cg / 100);
            $found[] = [
                'field' => $field,
                'price_cents' => $price,
                'cost_cents' => $costCents,
                'line' => $priceLabel.': '.Money::fromCents($price)->formatted().' — '.$costLabel.': '.Money::fromCents($costCents)->formatted(),
            ];
        };

        $check('per_gram', $perGramCents, 100, __('Precio por gramo'), __('Coste por gramo'));
        $check('per_unit', $perUnitCents, (int) $gramsPerUnitCg, __('Precio por unidad'), __('Coste por unidad'));
        $check('per_eighth', $perEighthCents, 350, __('Precio por octavo (3.5 g)'), __('Coste de 3.5 g'));

        return $found;
    }

    /**
     * Prompt 382 — the same rule for a Local / Personal price: the field is prefixed (`local_per_gram`) and the line names the
     * list («Local — Precio por gramo: …»). Pass the EFFECTIVE prices (a blank one is its default).
     *
     * @return list<array{field: string, price_cents: int, cost_cents: int, line: string}>
     */
    public static function forList(PriceList $list, ?int $costPerGramCents, ?int $perGramCents = null, ?int $perUnitCents = null, ?int $perEighthCents = null, ?int $gramsPerUnitCg = null): array
    {
        $found = self::offences($costPerGramCents, $perGramCents, $perUnitCents, $perEighthCents, $gramsPerUnitCg);
        if ($list === PriceList::STANDARD) {
            return $found;
        }

        return array_map(fn (array $o): array => ['field' => $list->columnPrefix().$o['field'], 'line' => $list->label().' — '.$o['line']] + $o, $found);
    }

    /**
     * Every list's below-cost prices on a batch — the Estándar ones first, then Local and Personal at what they charge.
     *
     * @return list<array{field: string, price_cents: int, cost_cents: int, line: string}>
     */
    public static function forBatchLists(Batch $batch): array
    {
        $gpu = $batch->genetic?->grams_per_unit_cg !== null ? (int) $batch->genetic->grams_per_unit_cg : null;
        $found = [];
        foreach (PriceList::cases() as $list) {
            $found = [...$found, ...self::forList($list, (int) $batch->cost_per_gram_cents,
                $batch->priceFor($list, 'gram')['cents'], $batch->priceFor($list, 'unit')['cents'], $batch->priceFor($list, 'eighth')['cents'], $gpu)];
        }

        return $found;
    }

    /** @return list<array{field: string, price_cents: int, cost_cents: int, line: string}> */
    public static function forBatch(Batch $batch): array
    {
        return self::offences(
            $batch->cost_per_gram_cents,
            $batch->price_per_gram_cents,
            $batch->price_per_unit_cents,
            $batch->price_per_eighth_cents,
            $batch->genetic?->grams_per_unit_cg !== null ? (int) $batch->genetic->grams_per_unit_cg : null,
        );
    }
}
