<?php

namespace App\Actions\Bar;

use App\Actions\Pricing\ResolveArticleDiscount;
use App\Actions\RecordAuditLog;
use App\Actions\Stock\RecordStockMovement;
use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\OrderStatus;
use App\Enums\StockMovementType;
use App\Enums\TillSessionStatus;
use App\Enums\WalletTransactionType;
use App\Exceptions\TillClosedException;
use App\Models\Article;
use App\Models\Location;
use App\Models\Member;
use App\Models\Order;
use App\Models\TillSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Commit a bar / merch order — the auxiliary-income counterpart to
 * CommitDispensation, deliberately on its OWN ledger (the Order) so bar takings
 * never bleed into cannabis contributions. Freezes an item snapshot with the unit
 * price at the moment of sale, depletes unit stock through the single stock writer
 * (SALE), optionally charges a member's wallet (a PURCHASE — member required), and
 * posts the cash line against the SHARED till session so the one drawer still
 * reconciles. Articles only: a catalogue line must resolve to an Article at this
 * location, so a genetic can never appear on a bar order.
 *
 * @phpstan-type OrderLine array{article_id?: string, description?: string, unit_price_cents?: int, qty?: int, reference?: string}
 * @phpstan-type OrderOptions array{operator_id?: ?string, till_session_id?: ?string, member_id?: ?string, cash_cents?: int, wallet_cents?: int, reference?: ?string, idempotency_key?: ?string, reversal_of_id?: ?string}
 */
class CommitOrder
{
    /**
     * @param  list<OrderLine>  $lines
     * @param  OrderOptions  $options
     */
    public function handle(Location $location, array $lines, array $options = []): Order
    {
        if ($lines === []) {
            throw new RuntimeException('An order needs at least one line.');
        }

        return DB::transaction(function () use ($location, $lines, $options): Order {
            // Idempotency: never double-commit the same basket.
            $key = $options['idempotency_key'] ?? null;
            if ($key !== null) {
                $existing = Order::withoutGlobalScopes()->where('idempotency_key', $key)->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            // An order may only attach to an OPEN till session (shared with the dispensary).
            $tillSessionId = $options['till_session_id'] ?? null;
            if ($tillSessionId !== null) {
                $till = TillSession::withoutGlobalScopes()->find($tillSessionId);
                if ($till === null || $till->status !== TillSessionStatus::OPEN) {
                    throw new TillClosedException('The order must attach to an open till session.');
                }
            }

            [$total, $items] = $this->buildItems($location, $lines, $options);

            $memberId = $options['member_id'] ?? null;
            $cash = $options['cash_cents'] ?? $total;
            $wallet = $options['wallet_cents'] ?? 0;

            if ($wallet > 0 && $memberId === null) {
                throw new RuntimeException('A wallet payment requires a member.');
            }

            $order = Order::create([
                'organisation_id' => $location->organisation_id,
                'location_id' => $location->id,
                'member_id' => $memberId,
                'operator_id' => $options['operator_id'] ?? Auth::id(),
                'till_session_id' => $tillSessionId,
                'items' => $items,
                'total_cents' => $total,
                'cash_cents' => $cash,
                'wallet_cents' => $wallet,
                'status' => OrderStatus::COMPLETED,
                'reversal_of_id' => $options['reversal_of_id'] ?? null,
                'reference' => $options['reference'] ?? null,
                'idempotency_key' => $key,
            ]);

            if ($wallet > 0) {
                $member = Member::withoutGlobalScopes()->findOrFail($memberId);
                (new RecordWalletTransaction)->handle($member, $location, -$wallet, WalletTransactionType::PURCHASE, [
                    'source' => $order,
                    'operator_id' => $options['operator_id'] ?? Auth::id(),
                    'till_session_id' => $tillSessionId,
                    'reason' => 'Compra en barra',
                    'allow_debt' => true, // the wallet writer enforces the debt limit itself
                ]);
            }

            (new RecordAuditLog)->handle('order.committed', $order, null, [
                'total_cents' => $total,
                'member_id' => $memberId,
            ]);

            return $order;
        });
    }

    /**
     * @param  list<OrderLine>  $lines
     * @param  OrderOptions  $options
     * @return array{0: int, 1: list<array<string, mixed>>}
     */
    private function buildItems(Location $location, array $lines, array $options): array
    {
        $total = 0;
        $items = [];

        // The member's bar/merch discount (prompt 55) — resolved ONCE via the single resolver the bar POS
        // also uses, so the charged total matches what the operator saw. Guests (no member) get 0.
        $discounter = new ResolveArticleDiscount;
        $member = isset($options['member_id']) ? Member::withoutGlobalScopes()->find($options['member_id']) : null;
        $articleDiscountBp = $member !== null ? $discounter->bpFor($member, $location) : 0;

        foreach ($lines as $line) {
            $qty = max(1, (int) ($line['qty'] ?? 1));

            if (isset($line['article_id'])) {
                // Catalogue line — MUST resolve to an Article at this location. A genetic id
                // will not, which is exactly how "no genetic on a bar order" is enforced.
                $article = Article::withoutGlobalScopes()
                    ->where('location_id', $location->id)
                    ->whereKey($line['article_id'])
                    ->firstOrFail();

                $unit = $article->price_cents->cents;
                $gross = $unit * $qty; // integer count × integer cents — no rounding
                $discount = $discounter->discountCents($gross, $articleDiscountBp);
                $lineTotal = $gross - $discount;
                $total += $lineTotal;

                // Single stock writer — locks the article row and refuses to oversell.
                (new RecordStockMovement)->handle($article, StockMovementType::SALE, -$qty, [
                    'operator_id' => $options['operator_id'] ?? Auth::id(),
                ]);

                $items[] = [
                    'article_id' => $article->id,
                    'name' => $article->name,
                    'unit_price_cents' => $unit,
                    'qty' => $qty,
                    'discount_cents' => $discount,
                    'line_total_cents' => $lineTotal,
                    'reference' => null,
                ];

                continue;
            }

            // Miscellaneous line — not in the catalogue, so a reference is required and no
            // stock moves.
            $reference = trim((string) ($line['reference'] ?? ''));
            if ($reference === '') {
                throw new RuntimeException('A miscellaneous line requires a reference.');
            }
            if (! isset($line['unit_price_cents'], $line['description'])) {
                throw new RuntimeException('A miscellaneous line needs a description and a price.');
            }

            $unit = (int) $line['unit_price_cents'];
            $lineTotal = $unit * $qty;
            $total += $lineTotal;

            $items[] = [
                'article_id' => null,
                'name' => (string) $line['description'],
                'unit_price_cents' => $unit,
                'qty' => $qty,
                'line_total_cents' => $lineTotal,
                'reference' => $reference,
            ];
        }

        return [$total, $items];
    }
}
