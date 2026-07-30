<?php

namespace App\Actions\Bar;

use App\Actions\RecordAuditLog;
use App\Actions\Stock\RecordStockMovement;
use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\OrderStatus;
use App\Enums\StockMovementType;
use App\Enums\WalletTransactionType;
use App\Models\Article;
use App\Models\Location;
use App\Models\Member;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Void a bar order — same discipline as the dispensary void, never a silent edit.
 * Returns each catalogue line's units to stock (a positive ADJUSTMENT through the
 * single writer; misc lines never moved stock) and credits any wallet-paid amount
 * back (a REFUND, deliberately not attributed to a till session — a wallet credit
 * is not a drawer movement). The cash side releases automatically: the till
 * expected-cash counts COMPLETED orders only, so a VOIDED order drops out.
 * Permissioned (order.void, manager+), reasoned, audited. A correction is this
 * void plus a fresh order linked via reversal_of_id.
 */
class VoidOrder
{
    public function handle(Order $order, User $actor, string $reason): Order
    {
        if (! $actor->can('order.void')) {
            throw new AuthorizationException('Voiding an order requires the order.void permission.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('A void requires a reason.');
        }

        return DB::transaction(function () use ($order, $actor, $reason): Order {
            /** @var Order $locked */
            $locked = Order::withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderStatus::COMPLETED) {
                throw new RuntimeException('Only a completed order can be voided.');
            }

            // Return each catalogue line's units to stock (single stock writer).
            foreach ($locked->items as $item) {
                $articleId = data_get($item, 'article_id');
                if ($articleId === null) {
                    continue; // misc line — no stock moved
                }
                $article = Article::withoutGlobalScopes()->find($articleId);
                if ($article === null) {
                    continue; // article since removed — nothing to return to
                }
                (new RecordStockMovement)->handle($article, StockMovementType::ADJUSTMENT, (int) data_get($item, 'qty', 0), [
                    'operator_id' => $actor->id,
                    'reason' => "Reversal of voided order {$locked->id}",
                    'reference' => $locked->id,
                ]);
            }

            // Credit any wallet-paid amount back to the member's wallet.
            if ($locked->wallet_cents->cents > 0 && $locked->member_id !== null) {
                $location = Location::withoutGlobalScopes()->findOrFail($locked->location_id);
                $member = Member::withoutGlobalScopes()->findOrFail($locked->member_id);
                (new RecordWalletTransaction)->handle($member, $location, $locked->wallet_cents->cents, WalletTransactionType::REFUND, [
                    'operator_id' => $actor->id,
                    'source' => $locked,
                    'reason' => "Reversal of voided order {$locked->id}",
                    'allow_debt' => true,
                ]);
            }

            $locked->update([
                'status' => OrderStatus::VOIDED,
                'void_reason' => $reason,
                'voided_by' => $actor->id,
                'voided_at' => now(),
            ]);

            (new RecordAuditLog)->handle('order.voided', $locked, null, [
                'reason' => $reason,
                'voided_by' => $actor->id,
                'total_cents' => $locked->total_cents->cents,
                'wallet_reversed_cents' => $locked->wallet_cents->cents,
            ]);

            return $locked;
        });
    }
}
