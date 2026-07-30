<?php

namespace App\Actions\Memberships;

use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\FeePaymentMethod;
use App\Enums\WalletTransactionType;
use App\Models\Membership;
use App\Models\MembershipFeePayment;
use Illuminate\Support\Facades\Auth;

/**
 * Record a membership fee payment. Fee income is a FIRST-CLASS income type,
 * reported separately from consumption contributions. A WALLET payment also posts
 * a FEE movement to the wallet ledger; a CASH payment attaches to the till session
 * for reconciliation (prompt 10).
 *
 * @phpstan-type FeeOptions array{till_session_id?: ?string, operator_id?: ?string, instalment_of?: ?string}
 */
class RecordFeePayment
{
    /**
     * @param  FeeOptions  $options
     */
    public function handle(Membership $membership, int $amountCents, FeePaymentMethod $method, array $options = []): MembershipFeePayment
    {
        $payment = MembershipFeePayment::create([
            'membership_id' => $membership->id,
            'amount_cents' => $amountCents,
            'method' => $method,
            'till_session_id' => $options['till_session_id'] ?? null,
            'paid_at' => now(),
            'recorded_by' => $options['operator_id'] ?? Auth::id(),
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

        return $payment;
    }
}
