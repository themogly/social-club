<?php

namespace App\Support;

use App\Models\Dispensation;
use App\Models\Order;

/**
 * What a commit actually DID, frozen at the moment it succeeded (prompt 202).
 *
 * The confirmation used to say *"Pedido registrado."* and nothing else — which tells the operator nothing
 * they had not already worked out from the basket emptying. Meanwhile the one figure a cash bar actually
 * needs, **the change due**, was zeroed before they could read it: `resetBasketState()` clears
 * `cashTendered`, and the change is derived from it.
 *
 * So the outcome is captured from the SETTLED transaction — the `Order` or `Dispensation` row that now
 * exists — rather than re-read from live cart fields that the reset has already destroyed. The one figure
 * that is not on either row is the change, because neither stores what the member HANDED OVER (prompt 74:
 * cash entered is the amount handed, never the amount charged), so it is passed in, computed before the
 * reset.
 *
 * A plain array rather than a value object: it lives on a Livewire component between requests, and an array
 * needs no `Wireable`. `null` amounts are omitted rather than rendered as zero — "Cambio €0,00" is noise on
 * a wallet-only payment.
 */
class SettledOutcome
{
    /**
     * @return array{total_cents: int, cash_cents: int, wallet_cents: int, change_cents: int, reference: ?string}
     */
    public static function forOrder(Order $order, int $changeCents = 0): array
    {
        return self::build(
            $order->total_cents->cents,
            $order->cash_cents->cents,
            $order->wallet_cents->cents,
            $changeCents,
            $order->reference,
        );
    }

    /**
     * @return array{total_cents: int, cash_cents: int, wallet_cents: int, change_cents: int, reference: ?string}
     */
    public static function forDispensation(Dispensation $dispensation, int $changeCents = 0): array
    {
        return self::build(
            $dispensation->total_cents->cents,
            $dispensation->cash_cents->cents,
            $dispensation->wallet_cents->cents,
            $changeCents,
            null, // a dispensation carries no ticket reference — that is a bar concept (prompt 193)
        );
    }

    /**
     * @return array{total_cents: int, cash_cents: int, wallet_cents: int, change_cents: int, reference: ?string}
     */
    private static function build(int $total, int $cash, int $wallet, int $change, ?string $reference): array
    {
        return [
            'total_cents' => $total,
            'cash_cents' => $cash,
            'wallet_cents' => $wallet,
            'change_cents' => max(0, $change),
            'reference' => ($reference !== null && trim($reference) !== '') ? trim($reference) : null,
        ];
    }
}
