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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
        return self::breakdownMany(new Collection([$session]))[$session->id];
    }

    /**
     * The breakdown for MANY sessions in a fixed number of grouped queries — one per ledger source, not one
     * per session (prompt 108). The till report renders a whole period of sessions and must not scale its
     * query count with their number. `breakdown()` delegates here with a single-element collection, so the
     * arithmetic and the per-model scoping (which tables strip global scopes, which do not) have ONE
     * definition and a batched figure can never disagree with a per-session one.
     *
     * @param  Collection<int, TillSession>  $sessions
     * @return array<string, Breakdown> keyed by session id
     */
    public static function breakdownMany(Collection $sessions): array
    {
        $ids = $sessions->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        $cashContributions = self::sumBySession(
            Dispensation::query()->withoutGlobalScopes()->where('status', DispensationStatus::COMPLETED->value), $ids, 'cash_cents');
        $walletContributions = self::sumBySession(
            Dispensation::query()->withoutGlobalScopes()->where('status', DispensationStatus::COMPLETED->value), $ids, 'wallet_cents');
        $barCash = self::sumBySession(
            Order::query()->withoutGlobalScopes()->where('status', OrderStatus::COMPLETED->value), $ids, 'cash_cents');
        $topUps = self::sumBySession(
            WalletTransaction::query()->withoutGlobalScopes()->where('type', WalletTransactionType::TOPUP->value), $ids, 'amount_cents');
        $refunds = self::sumBySession(
            WalletTransaction::query()->withoutGlobalScopes()->where('type', WalletTransactionType::REFUND->value), $ids, 'amount_cents'); // negative
        $feesCash = self::sumBySession(
            MembershipFeePayment::query()->where('method', FeePaymentMethod::CASH->value), $ids, 'amount_cents');

        // Full models (so the enum/Money casts hydrate) grouped by session, then reduced per session below.
        $movementsBySession = CashMovement::query()->whereIn('till_session_id', $ids)
            ->get(['till_session_id', 'type', 'amount_cents'])
            ->groupBy('till_session_id');

        $out = [];
        foreach ($sessions as $session) {
            $id = $session->id;
            $float = $session->float_cents->cents;

            /** @var Collection<int, CashMovement> $movements */
            $movements = $movementsBySession->get($id) ?? new Collection;
            $cashIn = self::sumType($movements, 'IN');
            $cashOut = self::sumType($movements, 'OUT');
            $banked = self::sumType($movements, 'BANKED');
            $pettyCash = self::sumType($movements, 'PETTY_CASH');

            $cash = $cashContributions[$id] ?? 0;
            $bar = $barCash[$id] ?? 0;
            $tu = $topUps[$id] ?? 0;
            $rf = $refunds[$id] ?? 0;
            $fc = $feesCash[$id] ?? 0;

            // Cash movements are stored signed (OUT/BANKED/PETTY negative), so they add.
            $expected = $float + $cash + $bar + $tu + $rf + $fc + $cashIn + $cashOut + $banked + $pettyCash;

            $out[$id] = [
                'float' => $float,
                'cash_contributions' => $cash,
                'wallet_contributions' => $walletContributions[$id] ?? 0,
                'bar_cash' => $bar,
                'top_ups' => $tu,
                'refunds' => $rf,
                'fees_cash' => $fc,
                'cash_in' => $cashIn,
                'cash_out' => $cashOut,
                'banked' => $banked,
                'petty_cash' => $pettyCash,
                'expected' => $expected,
            ];
        }

        return $out;
    }

    /**
     * SUM(column) grouped by till_session_id, as [sessionId => int], in one query. `get()` (not `pluck()`)
     * because pluck rewrites the select and would drop the aggregate alias; the aggregate is read via array
     * access, which returns the dynamic attribute without a declared-property assumption.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $ids
     * @return array<string, int>
     */
    private static function sumBySession(Builder $query, array $ids, string $column): array
    {
        $rows = $query->whereIn('till_session_id', $ids)
            ->groupBy('till_session_id')
            ->selectRaw("till_session_id, SUM({$column}) as agg")
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['till_session_id']] = (int) $row['agg'];
        }

        return $out;
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
