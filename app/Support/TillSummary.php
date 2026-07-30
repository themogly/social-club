<?php

namespace App\Support;

use App\Enums\DispensationStatus;
use App\Enums\FeePaymentMethod;
use App\Enums\OrderStatus;
use App\Enums\WalletTransactionType;
use App\Models\CashMovement;
use App\Models\Dispensation;
use App\Models\MembershipFeePayment;
use App\Models\Order;
use App\Models\TillSession;
use App\Models\WalletTransaction;
use Illuminate\Support\Collection;

/**
 * The live session summary — every figure DERIVED from the ledger, never typed or
 * cached. Only CASH counts toward expected drawer cash: wallet contributions/
 * payments are shown but excluded (the distinction naive tills get wrong). Voided
 * transactions are excluded, so a void adjusts the expected figure automatically.
 *
 * @phpstan-type Breakdown array{float: int, cash_contributions: int, wallet_contributions: int, bar_cash: int, top_ups: int, refunds: int, fees_cash: int, cash_in: int, cash_out: int, banked: int, petty_cash: int, expected: int}
 */
class TillSummary
{
    /**
     * @return Breakdown
     */
    public static function breakdown(TillSession $session): array
    {
        $id = $session->id;
        $float = $session->float_cents->cents;

        $cashContributions = (int) Dispensation::query()->withoutGlobalScopes()
            ->where('till_session_id', $id)->where('status', DispensationStatus::COMPLETED->value)->sum('cash_cents');
        $walletContributions = (int) Dispensation::query()->withoutGlobalScopes()
            ->where('till_session_id', $id)->where('status', DispensationStatus::COMPLETED->value)->sum('wallet_cents');
        $barCash = (int) Order::query()->withoutGlobalScopes()
            ->where('till_session_id', $id)->where('status', OrderStatus::COMPLETED->value)->sum('cash_cents');
        $topUps = (int) WalletTransaction::query()->withoutGlobalScopes()
            ->where('till_session_id', $id)->where('type', WalletTransactionType::TOPUP->value)->sum('amount_cents');
        $refunds = (int) WalletTransaction::query()->withoutGlobalScopes()
            ->where('till_session_id', $id)->where('type', WalletTransactionType::REFUND->value)->sum('amount_cents'); // negative
        $feesCash = (int) MembershipFeePayment::query()
            ->where('till_session_id', $id)->where('method', FeePaymentMethod::CASH->value)->sum('amount_cents');

        $movements = CashMovement::query()->where('till_session_id', $id)->get(['type', 'amount_cents']);
        $cashIn = self::sumType($movements, 'IN');
        $cashOut = self::sumType($movements, 'OUT');
        $banked = self::sumType($movements, 'BANKED');
        $pettyCash = self::sumType($movements, 'PETTY_CASH');

        // Cash movements are stored signed (OUT/BANKED/PETTY negative), so they add.
        $expected = $float + $cashContributions + $barCash + $topUps + $refunds + $feesCash
            + $cashIn + $cashOut + $banked + $pettyCash;

        return [
            'float' => $float,
            'cash_contributions' => $cashContributions,
            'wallet_contributions' => $walletContributions,
            'bar_cash' => $barCash,
            'top_ups' => $topUps,
            'refunds' => $refunds,
            'fees_cash' => $feesCash,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'banked' => $banked,
            'petty_cash' => $pettyCash,
            'expected' => $expected,
        ];
    }

    public static function expectedCents(TillSession $session): int
    {
        return self::breakdown($session)['expected'];
    }

    /**
     * @param  Collection<int, CashMovement>  $movements
     */
    private static function sumType(Collection $movements, string $type): int
    {
        // amount_cents is a Money value object (cast) — sum the raw cents, not the objects.
        return (int) $movements->where('type.value', $type)
            ->sum(fn (CashMovement $m) => $m->amount_cents->cents);
    }
}
