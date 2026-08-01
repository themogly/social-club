<?php

namespace App\Support;

use App\Enums\DispensationStatus;
use App\Enums\OrderStatus;
use App\Enums\TillSessionStatus;
use App\Models\Dispensation;
use App\Models\Order;
use App\Models\TillSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Session (Z) report: the till breakdown plus counted/variance, transaction and
 * void counts, operator and duration. Totals equal the sum of the underlying rows.
 * Printable/exportable; feeds the dashboard and financial reports (prompt 14).
 *
 * @return array<string, mixed>
 */
class ZReport
{
    /**
     * @return array<string, mixed>
     */
    public static function for(TillSession $session): array
    {
        return self::forMany(new Collection([$session]))[$session->id];
    }

    /**
     * The Z-report for MANY sessions in a fixed number of queries, keyed by session id (prompt 108). The
     * breakdown batches through `TillSummary::breakdownMany`, and the transaction/void counts through two
     * grouped COUNTs — so a period report over N sessions issues a constant number of queries, not ~12 per
     * session. `for()` delegates here so the single-session Filament infolist and the report share one shape.
     *
     * @param  Collection<int, TillSession>  $sessions
     * @return array<string, array<string, mixed>> keyed by session id
     */
    public static function forMany(Collection $sessions): array
    {
        if ($sessions->isEmpty()) {
            return [];
        }

        $ids = $sessions->pluck('id')->all();
        $breakdowns = TillSummary::breakdownMany($sessions);
        $dispCounts = self::countsBySession(Dispensation::query()->withoutGlobalScopes(), $ids, DispensationStatus::VOIDED->value);
        $orderCounts = self::countsBySession(Order::query()->withoutGlobalScopes(), $ids, OrderStatus::VOIDED->value);

        $out = [];
        foreach ($sessions as $session) {
            $id = $session->id;
            $breakdown = $breakdowns[$id];
            $liveExpected = $breakdown['expected'];

            // A CLOSED session reports the expected figure it was CLOSED with — the one CloseTill stored under
            // lock (prompt 103) — never a live recomputation, so a later void of one of its dispensations can no
            // longer rewrite a signed cash-up. An OPEN session keeps the live derivation (prompt 42 relies on it,
            // and it is the number the operator counts against). If the ledger has moved since close (a post-close
            // void/correction), flag it so the amended session is visible rather than silently changed.
            $postCloseAdjusted = false;
            if ($session->status === TillSessionStatus::CLOSED && $session->expected_cents !== null) {
                $breakdown['expected'] = $session->expected_cents->cents;
                $postCloseAdjusted = $liveExpected !== $breakdown['expected'];
            }

            $out[$id] = array_merge($breakdown, [
                'counted' => $session->counted_cents?->cents,
                'variance' => $session->variance_cents?->cents,
                // The live recomputation, kept alongside the frozen figure so a post-close change is inspectable.
                'expected_live' => $liveExpected,
                'post_close_adjusted' => $postCloseAdjusted,
                'transaction_count' => ($dispCounts[$id]['total'] ?? 0) + ($orderCounts[$id]['total'] ?? 0),
                'voids' => ($dispCounts[$id]['voided'] ?? 0) + ($orderCounts[$id]['voided'] ?? 0),
                'opened_at' => $session->opened_at,
                'closed_at' => $session->closed_at,
                'operator' => $session->openedBy?->name,
                'status' => $session->status->label(), // localized label, never the raw enum (prompt 94)
            ]);
        }

        return $out;
    }

    /**
     * Total rows and voided rows per session in ONE grouped query, as [sessionId => ['total' => int,
     * 'voided' => int]]. The `SUM(CASE …)` counts voids without a second round-trip; the aggregates are read
     * via array access, which returns the dynamic attribute without a declared-property assumption.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $ids
     * @return array<string, array{total: int, voided: int}>
     */
    private static function countsBySession(Builder $query, array $ids, string $voidStatus): array
    {
        $rows = $query->whereIn('till_session_id', $ids)
            ->groupBy('till_session_id')
            ->selectRaw('till_session_id, COUNT(*) as total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as voided', [$voidStatus])
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['till_session_id']] = ['total' => (int) $row['total'], 'voided' => (int) $row['voided']];
        }

        return $out;
    }
}
