<?php

namespace App\Actions\Dispensing;

use App\Actions\RecordAuditLog;
use App\Actions\Stock\RecordStockMovement;
use App\Actions\Till\RecordCashMovement;
use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\CashMovementType;
use App\Enums\DispensationStatus;
use App\Enums\RefundDestination;
use App\Enums\RefundMethod;
use App\Enums\StockMovementType;
use App\Enums\WalletTransactionType;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\Location;
use App\Models\Member;
use App\Models\Refund;
use App\Models\TillSession;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A PARTIAL, reversible refund against a completed dispensation (prompt 65). It reuses VoidDispensation's
 * mechanics — grams back through the single stock writer, money back through the wallet or till writer —
 * but a refund NEVER mutates the original (that stays COMPLETED); each refund is a fresh, linked Refund row.
 *
 * Two things a full void doesn't have to worry about, this does:
 *  - The dispensation stays COMPLETED, so its cash/limit arithmetic is NOT self-correcting. A cash refund
 *    must therefore post an explicit OUT movement on the OPEN till so the arqueo drops by the refund; a
 *    wallet refund credits the wallet (no drawer impact). No open till ⇒ a cash refund is refused.
 *  - Partial + repeated refunds must never over-refund: cumulative amount AND weight ≤ the original.
 *    Enforced under a lockForUpdate on the header, so two concurrent partials serialise and the second
 *    sees the first's committed row. Returned product is the operator's explicit choice — good product
 *    back to its batch (sellable), unsellable product written off as MERMA (never re-entering sellable
 *    stock) — with no silent default. Refunds are `dispensation.void` (manager+), reasoned and audited.
 *
 * @phpstan-type RefundData array{amount_cents: int, grams_cg?: int, destination: RefundDestination, method: RefundMethod, reason: string, till_session?: ?TillSession, batch_id?: ?string}
 */
class RefundDispensation
{
    /**
     * @param  RefundData  $data
     */
    public function handle(Dispensation $dispensation, User $actor, array $data): Refund
    {
        // Authorize through the POLICY (prompt 75): DispensationPolicy::refund binds the actor to the row's
        // OWN sede, so a manager at sede A cannot refund a sede-B dispensation — the same gap as void.
        if ($actor->cannot('refund', $dispensation)) {
            throw new AuthorizationException('Refunding a dispensation requires dispensation.void at the row\'s location.');
        }

        $reason = trim($data['reason']);
        if ($reason === '') {
            throw new RuntimeException('A refund requires a reason.');
        }

        $amount = $data['amount_cents'];
        $grams = (int) ($data['grams_cg'] ?? 0);
        if ($amount < 0 || $grams < 0) {
            throw new RuntimeException('A refund cannot be negative.');
        }
        if ($amount === 0 && $grams === 0) {
            throw new RuntimeException('A refund must return money, product, or both.');
        }

        $destination = $data['destination'];
        $method = $data['method'];
        $tillSession = $data['till_session'] ?? null;

        // A cash refund takes money OUT of the drawer, so it must attach to an open till session.
        if ($method === RefundMethod::CASH && ! $tillSession instanceof TillSession) {
            throw new RuntimeException('A cash refund requires an open till session.');
        }

        return DB::transaction(function () use ($dispensation, $actor, $reason, $amount, $grams, $destination, $method, $tillSession, $data): Refund {
            // Lock the header so two partial refunds cannot both read a stale refunded-so-far total.
            /** @var Dispensation $locked */
            $locked = Dispensation::withoutGlobalScopes()->whereKey($dispensation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== DispensationStatus::COMPLETED) {
                throw new RuntimeException('Only a completed dispensation can be refunded.');
            }

            // Refund window (a real, configurable control): a dispensation older than the window cannot be
            // refunded at the counter. 0 = no window. Regional practice varies, so it is a Setting, not a const.
            $windowDays = (int) Settings::get('refund_window_days', 30, $locked->location_id);
            $reference = $locked->dispensed_at ?? $locked->created_at;
            if ($windowDays > 0 && $reference !== null && $reference->lt(now()->subDays($windowDays))) {
                throw new RuntimeException("This dispensation is older than the {$windowDays}-day refund window.");
            }

            // Over-refund guard — cumulative amount AND weight can never exceed the original. Read INSIDE the
            // lock so a concurrent partial refund is already committed and counted before this one is allowed.
            $refundedAmount = (int) Refund::withoutGlobalScopes()->where('dispensation_id', $locked->id)->sum('amount_cents');
            $refundedGrams = (int) Refund::withoutGlobalScopes()->where('dispensation_id', $locked->id)->sum('grams_cg');

            if ($amount + $refundedAmount > $locked->total_cents->cents) {
                throw new RuntimeException('This refund would exceed the amount charged.');
            }

            $dispensedGrams = (int) $locked->lines()->withoutGlobalScopes()->sum('grams_cg');
            if ($grams + $refundedGrams > $dispensedGrams) {
                throw new RuntimeException('This refund would exceed the weight dispensed.');
            }

            // Resolve the batch the grams came from (single-line: the line's batch; multi-line: caller picks).
            $batchId = $data['batch_id'] ?? ($grams > 0 ? $locked->lines()->withoutGlobalScopes()->value('batch_id') : null);
            if ($grams > 0 && $batchId === null) {
                throw new RuntimeException('A product refund needs a batch to return the grams to.');
            }

            $refund = Refund::create([
                'organisation_id' => $locked->organisation_id,
                'location_id' => $locked->location_id,
                'dispensation_id' => $locked->id,
                'member_id' => $locked->member_id,
                'amount_cents' => $amount,
                'grams_cg' => $grams,
                'destination' => $destination,
                'method' => $method,
                'batch_id' => $batchId,
                'till_session_id' => $method === RefundMethod::CASH ? $tillSession->id : null,
                'reason' => $reason,
                'refunded_by' => $actor->id,
            ]);

            // --- Product side ---
            if ($grams > 0) {
                /** @var Batch $batch */
                $batch = Batch::withoutGlobalScopes()->whereKey($batchId)->firstOrFail();

                // The product physically comes back to the batch either way (a positive ADJUSTMENT through
                // the single stock writer, which locks the batch row).
                (new RecordStockMovement)->handle($batch, StockMovementType::ADJUSTMENT, $grams, [
                    'actor' => $actor,
                    'operator_id' => $actor->id,
                    'reason' => "Refund of dispensation {$locked->id}",
                    'reference' => $refund->id,
                ]);

                // If it is unsellable, it is then written off as MERMA (net stock effect zero — it must not
                // re-enter sellable stock). MERMA is gated on stock.merma inside RecordStockMovement.
                if ($destination === RefundDestination::MERMA) {
                    (new RecordStockMovement)->handle($batch, StockMovementType::MERMA, -$grams, [
                        'actor' => $actor,
                        'operator_id' => $actor->id,
                        'reason' => "Unsellable refund of dispensation {$locked->id}",
                        'reference' => $refund->id,
                    ]);
                }
            }

            // --- Money side ---
            if ($amount > 0) {
                if ($method === RefundMethod::WALLET) {
                    $location = Location::withoutGlobalScopes()->findOrFail($locked->location_id);
                    /** @var Member $member */
                    $member = $locked->member()->withoutGlobalScopes()->firstOrFail();

                    // A wallet credit is not a drawer movement, so it carries no till session (that attribution
                    // is what would pull it into the arqueo). allow_debt: a refund must always be creditable.
                    (new RecordWalletTransaction)->handle($member, $location, $amount, WalletTransactionType::REFUND, [
                        'operator_id' => $actor->id,
                        'source' => $refund,
                        'reason' => "Refund of dispensation {$locked->id}",
                        'allow_debt' => true,
                    ]);
                } else {
                    // Cash OUT of the open drawer — TillSummary counts it, so the expected figure drops by
                    // exactly the refund (RecordCashMovement refuses a closed session). $tillSession is
                    // guaranteed non-null here: the entry guard throws on a CASH refund without one.
                    (new RecordCashMovement)->handle($tillSession, CashMovementType::OUT, $amount, [
                        'operator_id' => $actor->id,
                        'reason' => "Refund of dispensation {$locked->id}",
                    ]);
                }
            }

            (new RecordAuditLog)->handle('dispensation.refunded', $locked, [
                'refunded_amount_cents' => $refundedAmount,
                'refunded_grams_cg' => $refundedGrams,
            ], [
                'refund_id' => $refund->id,
                'amount_cents' => $amount,
                'grams_cg' => $grams,
                'destination' => $destination->value,
                'method' => $method->value,
                'reason' => $reason,
                'refunded_by' => $actor->id,
                'cumulative_amount_cents' => $refundedAmount + $amount,
                'cumulative_grams_cg' => $refundedGrams + $grams,
            ]);

            return $refund;
        });
    }
}
