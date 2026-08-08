<?php

namespace App\Actions\Memberships;

use App\Actions\RecordAuditLog;
use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\FeePaymentMethod;
use App\Enums\WalletTransactionType;
use App\Models\Membership;
use App\Models\MembershipFeePayment;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Record a membership fee payment. Fee income is a FIRST-CLASS income type,
 * reported separately from consumption contributions. A WALLET payment also posts
 * a FEE movement to the wallet ledger; a CASH payment attaches to the till session
 * for reconciliation (prompt 10).
 *
 * **A WAIVER goes through here too** (prompt 219), as a row with method `WAIVED`: the club charged the fee and
 * chose to forgo it. One writer, so the debt clears everywhere `amount_cents` is summed — the door notice, the
 * `unpaid_fee` verdict, the fee panels, renewal — with no consumer changed and no edit to `fee_cents`. It
 * touches no drawer and no wallet, and it carries a REASON and an audit entry, because forgoing income is a
 * governance act with an author rather than a payment that never happened.
 *
 * @phpstan-type FeeOptions array{till_session_id?: ?string, operator_id?: ?string, instalment_of?: ?string, reason?: ?string}
 */
class RecordFeePayment
{
    /**
     * @param  FeeOptions  $options
     */
    public function handle(Membership $membership, int $amountCents, FeePaymentMethod $method, array $options = []): MembershipFeePayment
    {
        $operatorId = $options['operator_id'] ?? Auth::id();
        $reason = isset($options['reason']) ? trim((string) $options['reason']) : null;

        // A waiver without a reason is not a waiver, it is a hole in the register. Refused HERE — at the
        // writer — rather than only in the form, so no future caller can skip it.
        if ($method === FeePaymentMethod::WAIVED && ($reason === null || $reason === '')) {
            throw new InvalidArgumentException('A waived fee requires a reason.');
        }

        $payment = MembershipFeePayment::create([
            'membership_id' => $membership->id,
            'amount_cents' => $amountCents,
            'method' => $method,
            'reason' => $reason !== '' ? $reason : null,
            // A waiver moves no cash, so it never attaches to a drawer even if a session is open.
            'till_session_id' => $method === FeePaymentMethod::WAIVED ? null : ($options['till_session_id'] ?? null),
            'paid_at' => now(),
            'recorded_by' => $operatorId,
            'instalment_of' => $options['instalment_of'] ?? null,
        ]);

        if ($method === FeePaymentMethod::WALLET) {
            (new RecordWalletTransaction)->handle(
                $membership->member,
                $membership->location,
                -$amountCents,
                WalletTransactionType::FEE,
                [
                    'source' => $payment,
                    'operator_id' => $options['operator_id'] ?? Auth::id(),
                    'till_session_id' => $options['till_session_id'] ?? null,
                    'reason' => 'Cuota de socio',
                ],
            );
        }

        // The governance record: who forwent how much, for which member, and why. `RecordAuditLog` is the
        // one audit writer (CLAUDE.md), so this is a call rather than a second log.
        if ($method === FeePaymentMethod::WAIVED) {
            (new RecordAuditLog)->handle('membership.fee.waived', $membership, null, [
                'member_id' => $membership->member_id,
                'membership_id' => $membership->id,
                'amount_cents' => $amountCents,
                'reason' => $reason,
                'operator_id' => $operatorId,
            ]);
        }

        return $payment;
    }
}
