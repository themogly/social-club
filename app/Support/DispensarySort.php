<?php

namespace App\Support;

/**
 * Prompt 351 — the order of the dispensary's strain list.
 *
 * Each sede has a default (`dispensary_sort`, highest price first unless set otherwise); the counter's €↓ / €↑ / A–Z switch
 * picks between the three orders in the browser. The rule lives ONLY here: the server ranks every card in all three
 * orders and writes the ranks onto the card (`data-rank-*`), so the switch moves cards without a request and without a
 * second copy of the rule in JavaScript.
 *
 * - **Price** is the price the card shows (`rate_cents`: the sede price of the batch the counter would sell from first,
 *   before any member discount — per gram or per unit).
 * - **Weight products come before unit products** in the price orders: €/g and €/ud are not comparable.
 * - **Ties** keep the alphabetical order (the catalogue query's `orderBy('name')`).
 * - **No stock last** in the price orders. *A–Z* is exactly the order before 351.
 */
final class DispensarySort
{
    public const PRICE_DESC = 'price_desc';

    public const PRICE_ASC = 'price_asc';

    public const ALPHA = 'alpha';

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::PRICE_DESC => __('Precio: de mayor a menor'),
            self::PRICE_ASC => __('Precio: de menor a mayor'),
            self::ALPHA => __('Alfabético'),
        ];
    }

    /** The sede's default order; anything unrecognised reads as highest first. */
    public static function forLocation(string $locationId): string
    {
        $mode = (string) Settings::get('dispensary_sort', self::PRICE_DESC, $locationId);

        return array_key_exists($mode, self::options()) ? $mode : self::PRICE_DESC;
    }

    /**
     * The rows (in alphabetical order, as the catalogue query returns them), each given its rank in every order, and
     * returned in `$mode`'s order.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function rank(array $rows, string $mode): array
    {
        foreach (array_keys(self::options()) as $order) {
            $sorted = array_keys($rows);
            usort($sorted, fn (int $a, int $b): int => self::compare($rows[$a], $rows[$b], $order) ?: $a <=> $b);
            foreach ($sorted as $position => $index) {
                $rows[$index]['rank'][$order] = $position;
            }
        }

        usort($rows, fn (array $a, array $b): int => $a['rank'][$mode] <=> $b['rank'][$mode]);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private static function compare(array $a, array $b, string $order): int
    {
        if ($order === self::ALPHA) {
            return 0;
        }

        return [! $a['has_batch'], (bool) $a['is_unit']] <=> [! $b['has_batch'], (bool) $b['is_unit']]
            ?: ($order === self::PRICE_DESC ? (int) $b['rate_cents'] <=> (int) $a['rate_cents'] : (int) $a['rate_cents'] <=> (int) $b['rate_cents']);
    }
}
