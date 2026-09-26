<?php

namespace App\Actions\Wallet;

use App\Actions\RecordAuditLog;
use App\Enums\WalletTransactionType;
use App\Exceptions\DebtLimitExceededException;
use App\Models\Location;
use App\Models\Member;
use App\Models\WalletTransaction;
use App\Support\Wallet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A counter sale paid from the member's wallet — the one place a sale may put a member on their TAB (prompt 259).
 *
 * `CommitDispensation`, `CommitOrder` (and so `CommitCombinedSettle`) used to record their wallet spend with
 * `allow_debt => true`, which skipped the debt check entirely: with debt switched OFF club-wide, a dispensation
 * paid from an empty wallet committed and left the member at −€7.23. A tab opened silently, as a side effect.
 *
 * Now a sale's wallet spend may exceed the member's credit at this sede ONLY when the operator chose "Añadir a la
 * cuenta" (`$onTab`) — a deliberate act, never a side effect — and even then only within the member's approved
 * headroom, which the wallet writer enforces (the member's `debt_limit_cents` against their total debt across
 * every sede, the sede's master switch, the club cap). The part that went on the tab is audited: who, whose tab,
 * how much, which sale.
 *
 * The member row is locked BEFORE the balance is read (re-entrant inside the caller's transaction — the same lock
 * `CommitDispensation` and the wallet writer take, prompt 77), so the credit measured here is the one debited.
 */
class SpendFromWallet
{
    /** @param  array{operator_id?: ?string, till_session_id?: ?string, reason?: ?string}  $options */
    public function handle(Member $member, Location $location, int $cents, WalletTransactionType $type, Model $sale, bool $onTab, array $options = []): WalletTransaction
    {
        return DB::transaction(fn (): WalletTransaction => $this->spend($member, $location, $cents, $type, $sale, $onTab, $options));
    }

    /** @param  array{operator_id?: ?string, till_session_id?: ?string, reason?: ?string}  $options */
    private function spend(Member $member, Location $location, int $cents, WalletTransactionType $type, Model $sale, bool $onTab, array $options): WalletTransaction
    {
        Member::withoutGlobalScopes()->whereKey($member->id)->lockForUpdate()->first();

        $credit = max(0, Wallet::balance($member->id, $location->id));
        $toTab = max(0, $cents - $credit);

        if ($toTab > 0 && ! $onTab) {
            throw new DebtLimitExceededException(__('El saldo del monedero no cubre el pago. Solo se puede añadir a la cuenta de un socio aprobado, con «Añadir a la cuenta».'));
        }

        $transaction = (new RecordWalletTransaction)->handle($member, $location, -$cents, $type, array_merge($options, [
            'source' => $sale,
        ]));

        if ($toTab > 0) {
            (new RecordAuditLog)->handle('wallet.tab.added', $member, null, [
                'location_id' => $location->id,
                'added_cents' => $toTab,
                'sale_type' => $sale->getMorphClass(),
                'sale_id' => $sale->getKey(),
                'operator_id' => $options['operator_id'] ?? null,
                'balance_after_cents' => $transaction->balance_after_cents,
            ]);
        }

        return $transaction;
    }
}
