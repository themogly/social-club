<?php

namespace App\Support;

use App\Enums\CashPot;
use App\Enums\DispensationStatus;
use App\Enums\ExpenseKind;
use App\Enums\FeePaymentMethod;
use App\Enums\OrderStatus;
use App\Enums\WalletTransactionType;
use App\Models\CashMovement;
use App\Models\Dispensation;
use App\Models\Expense;
use App\Models\MembershipFeePayment;
use App\Models\Order;
use App\Models\TillSession;
use App\Models\WalletTransaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The live session summary — every figure DERIVED from the ledger, never typed or
 * cached. Only CASH counts toward expected drawer cash: wallet contributions/
 * payments are shown but excluded (the distinction naive tills get wrong). Voided
 * transactions are excluded, so a void adjusts the expected figure automatically.
 *
 * @phpstan-type Breakdown array{float: int, cash_contributions: int, wallet_contributions: int, bar_cash: int, top_ups: int, refunds: int, fees_cash: int, cash_in: int, cash_out: int, banked: int, petty_cash: int, petty_cash_items: list<array{category: string, note: ?string, amount_cents: int, recorded_by: string, at: string}>, expected: int, separate_pots: bool, own_boxes: list<string>, edibles_cash: int, shop_cash: int, pots: array<string, array{opening: int, expected: int}>}
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
     * One session's till expenses as the plain rows every view renders (prompt 265).
     *
     * @param  iterable<int, Expense>|null  $expenses
     * @return list<array{category: string, note: ?string, amount_cents: int, recorded_by: string, at: string}>
     */
    private static function pettyCashItems(?iterable $expenses): array
    {
        $items = [];

        foreach ($expenses ?? [] as $expense) {
            $items[] = [
                'category' => (string) ($expense->category->name ?? '—'),
                'note' => $expense->note,
                'amount_cents' => $expense->amount_cents->cents,
                'recorded_by' => (string) ($expense->recorder->name ?? '—'),
                'at' => local_datetime($expense->created_at, 'H:i'), // the sede's wall clock (271)
            ];
        }

        return $items;
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
        // Prompt 373 — the cash that paid for edibles, fixed at commit (CommitDispensation's one rule).
        $ediblesCash = self::sumBySession(
            Dispensation::query()->withoutGlobalScopes()->where('status', DispensationStatus::COMPLETED->value), $ids, 'edibles_cash_cents');
        $barCash = self::sumBySession(
            Order::query()->withoutGlobalScopes()->where('status', OrderStatus::COMPLETED->value), $ids, 'cash_cents');
        // Prompt 378 — the part of the orders' cash that paid for shop items, fixed at commit (CommitOrder's one rule).
        $shopCash = self::sumBySession(
            Order::query()->withoutGlobalScopes()->where('status', OrderStatus::COMPLETED->value), $ids, 'shop_cash_cents');
        $topUps = self::sumBySession(
            WalletTransaction::query()->withoutGlobalScopes()->where('type', WalletTransactionType::TOPUP->value), $ids, 'amount_cents');
        $refunds = self::sumBySession(
            WalletTransaction::query()->withoutGlobalScopes()->where('type', WalletTransactionType::REFUND->value), $ids, 'amount_cents'); // negative
        $feesCash = self::sumBySession(
            MembershipFeePayment::query()->where('method', FeePaymentMethod::CASH->value), $ids, 'amount_cents');

        // Prompt 265 — what each petty-cash expense was FOR. The till expenses behind the PETTY_CASH movements, one
        // grouped query for all the sessions, itemised per session so every view (the till, the closed arqueo, the
        // admin session, the till report) reads the SAME list rather than querying expenses on its own.
        $itemsBySession = Expense::query()->withoutGlobalScopes()
            ->whereIn('till_session_id', $ids)
            ->where('kind', ExpenseKind::TILL->value)
            ->with(['category' => fn ($q) => $q->withoutGlobalScopes(), 'recorder'])
            ->orderBy('created_at')
            ->get()
            ->groupBy('till_session_id');

        // Full models (so the enum/Money casts hydrate) grouped by session, then reduced per session below.
        $movementsBySession = CashMovement::query()->whereIn('till_session_id', $ids)
            ->get(['till_session_id', 'type', 'amount_cents', 'pot'])
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

            $ed = $ediblesCash[$id] ?? 0;
            $shop = $shopCash[$id] ?? 0;

            // Prompt 349 / 373 — the cash per POT. Which source feeds which pot is decided HERE and nowhere else:
            //   the till (DISPENSARY) — the float, cash contributions, wallet top-ups taken in cash, refunds paid out, its
            //                          movements, AND every kind of money that has no box of its own this session;
            //   edibles (own box)    — its carried opening, the edibles' cash (fixed at commit), its movements;
            //   bar (own box)        — its carried opening, the bar's cash (and the shop's «Con la barra»), its movements;
            //   shop (own box)       — its carried opening, the shop's cash (fixed at commit), its movements (prompt 378);
            //   fees (own box)       — its carried opening, membership fees in cash, its movements.
            // Prompt 378 — each kind of money goes to `$session->cashPotFor(kind)`: its box, or the till (the shop «Con la barra»
            // follows the bar). The orders' cash is the bar's minus the shop's part; a session from before 378 has no shop part.
            // A pot without its own box reads 0 (its money is in the till). Refunds and cash top-ups always stay with the till.
            // With no boxes, the till is exactly the single drawer of before; with 349's bar + fees, exactly 349's figures.
            $own = fn (CashPot $pot): bool => $session->hasOwnBox($pot);
            $potMovements = fn (CashPot $pot): int => (int) $movements
                ->filter(fn (CashMovement $m): bool => ($pot === CashPot::DISPENSARY && ! $own($m->pot ?? CashPot::DISPENSARY)) || ($pot !== CashPot::DISPENSARY && ($m->pot ?? CashPot::DISPENSARY) === $pot))
                ->sum(fn (CashMovement $m): int => $m->amount_cents->cents);
            $byKind = [CashPot::EDIBLES->value => $ed, CashPot::BAR->value => $bar - $shop, CashPot::SHOP->value => $shop, CashPot::FEES->value => $fc];
            $into = [CashPot::DISPENSARY->value => $cash - $ed]; // the dispensary's own cash is always the till's
            foreach ($byKind as $kind => $cents) {
                $pot = $session->cashPotFor(CashPot::from($kind))->value;
                $into[$pot] = ($into[$pot] ?? 0) + $cents;
            }
            $till = $float + $into[CashPot::DISPENSARY->value] + $tu + $rf + $potMovements(CashPot::DISPENSARY);
            $pots = [CashPot::DISPENSARY->value => ['opening' => $float, 'expected' => $till]];
            foreach (CashPot::optional() as $pot) {
                $opening = $own($pot) ? (int) $session->getRawOriginal($pot->column().'_opening_cents') : 0;
                $pots[$pot->value] = ['opening' => $opening, 'expected' => $own($pot) ? $opening + ($into[$pot->value] ?? 0) + $potMovements($pot) : 0];
            }
            $separate = $session->ownBoxes() !== [];

            // The headline «Efectivo esperado en el cajón»: the till's — the float is its float, and it is the one counted
            // every night. With no boxes it is the whole drawer.
            $expected = $till;

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
                'petty_cash_items' => self::pettyCashItems($itemsBySession->get($id)),
                'expected' => $expected,
                'separate_pots' => $separate,
                'own_boxes' => array_map(fn (CashPot $pot): string => $pot->value, $session->ownBoxes()),
                'edibles_cash' => $ed,
                'shop_cash' => $shop,
                'pots' => $pots,
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

    /**
     * Prompt 349 — since when an optional pot (bar, fees) has gone uncounted at this session's terminal: the close of the
     * last session there that counted it (or the first session that kept pots). Null when it was counted at the last close.
     */
    public static function uncountedSince(TillSession $session, CashPot $pot): ?CarbonInterface
    {
        $column = $pot->column().'_counted_cents';
        // Prompt 373 — only the closes where this kind of money WAS its own box (a night in the till breaks the run).
        $previous = TillSession::query()->withoutGlobalScopes()
            ->where('location_id', $session->location_id)->where('terminal', $session->terminal)
            ->whereNotNull('closed_at')->where('id', '!=', $session->id)
            ->orderByDesc('closed_at')->orderByDesc('id')->get(['id', 'closed_at', 'opened_at', 'own_boxes', $column])
            ->takeWhile(fn (TillSession $s): bool => $s->hasOwnBox($pot));

        if ($previous->isEmpty() || $previous->first()->getRawOriginal($column) !== null) {
            return null;
        }
        $lastCounted = $previous->first(fn (TillSession $s): bool => $s->getRawOriginal($column) !== null);
        if ($lastCounted !== null) {
            return $lastCounted->closed_at;
        }

        return $previous->last()->opened_at;
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
