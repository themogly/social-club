<?php

namespace App\Support;

use App\Enums\DispensationStatus;
use App\Enums\OrderStatus;
use App\Enums\TillSessionStatus;
use App\Models\Dispensation;
use App\Models\Order;
use App\Models\TillSession;

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
        $dispensations = Dispensation::query()->withoutGlobalScopes()->where('till_session_id', $session->id);
        $orders = Order::query()->withoutGlobalScopes()->where('till_session_id', $session->id);

        $breakdown = TillSummary::breakdown($session);
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

        return array_merge($breakdown, [
            'counted' => $session->counted_cents?->cents,
            'variance' => $session->variance_cents?->cents,
            // The live recomputation, kept alongside the frozen figure so a post-close change is inspectable.
            'expected_live' => $liveExpected,
            'post_close_adjusted' => $postCloseAdjusted,
            'transaction_count' => (clone $dispensations)->count() + (clone $orders)->count(),
            'voids' => (clone $dispensations)->where('status', DispensationStatus::VOIDED->value)->count()
                + (clone $orders)->where('status', OrderStatus::VOIDED->value)->count(),
            'opened_at' => $session->opened_at,
            'closed_at' => $session->closed_at,
            'operator' => $session->openedBy?->name,
            'status' => $session->status->label(), // localized label, never the raw enum (prompt 94)
        ]);
    }
}
