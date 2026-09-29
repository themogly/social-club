<?php

namespace App\Support;

/**
 * Prompt 303 — *Repartir a partes iguales*: an integer amount (centigrams or units) split into `parts` shares that add up
 * to exactly the amount. The division never loses anything: the leftover goes to the first share.
 */
final class SplitQuantity
{
    /** @return list<int> */
    public static function evenly(int $amount, int $parts): array
    {
        if ($parts < 1) {
            return [];
        }

        $share = intdiv($amount, $parts);
        $shares = array_fill(0, $parts, $share);
        $shares[0] += $amount - $share * $parts;

        return $shares;
    }
}
