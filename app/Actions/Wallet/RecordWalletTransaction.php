<?php

namespace App\Actions\Wallet;

use App\Actions\RecordAuditLog;
use App\Enums\WalletTransactionType;
use App\Exceptions\DebtLimitExceededException;
use App\Models\Location;
use App\Models\Member;
use App\Models\WalletTransaction;
use App\Support\Settings;
use App\Support\Wallet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY writer to the wallet ledger. Appends a signed movement, computes
 * balance_after from the prior balance (never free-typed), and enforces the debt
 * limit — a movement that would push debt past the configured cap is refused
 * (unless it is an explicit permissioned adjustment/transfer). Attributed to an
 * operator and, for cash movements, a till session.
 *
 * @phpstan-type Options array{operator_id?: ?string, till_session_id?: ?string, reason?: ?string, source?: ?Model, transfer_pair_id?: ?string, allow_debt?: bool}
 */
class RecordWalletTransaction
{
    /**
     * @param  Options  $options
     */
    public function handle(Member $member, Location $location, int $amountCents, WalletTransactionType $type, array $options = []): WalletTransaction
    {
        return DB::transaction(function () use ($member, $location, $amountCents, $type, $options): WalletTransaction {
            $newBalance = Wallet::balance($member->id, $location->id) + $amountCents;

            if ($newBalance < 0 && ! ($options['allow_debt'] ?? false)) {
                $debtAllowed = (bool) Settings::get('wallet_debt_allowed', false);
                $limit = (int) Settings::get('wallet_debt_limit_cents', 0);

                if (! $debtAllowed || $newBalance < -$limit) {
                    throw new DebtLimitExceededException(__('Este movimiento superaría el límite de deuda del socio.'));
                }
            }

            $source = $options['source'] ?? null;

            $transaction = WalletTransaction::create([
                'organisation_id' => $member->organisation_id,
                'member_id' => $member->id,
                'location_id' => $location->id,
                'amount_cents' => $amountCents,
                'type' => $type,
                'balance_after_cents' => $newBalance,
                'operator_id' => $options['operator_id'] ?? Auth::id(),
                'till_session_id' => $options['till_session_id'] ?? null,
                'reason' => $options['reason'] ?? null,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'transfer_pair_id' => $options['transfer_pair_id'] ?? null,
            ]);

            // Manual permissioned adjustments are audited (prompt 48) — INSIDE the txn, so a failed
            // audit rolls back the movement (matches CommitStockTake's boundary). Other movement types
            // are traced in the wallet ledger itself and are not flooded into the audit log.
            if ($type === WalletTransactionType::ADJUSTMENT) {
                (new RecordAuditLog)->handle('wallet.adjusted', $member,
                    ['balance_cents' => $newBalance - $amountCents],
                    ['balance_cents' => $newBalance, 'reason' => $options['reason'] ?? null]);
            }

            return $transaction;
        });
    }
}
