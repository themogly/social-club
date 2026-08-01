<?php

namespace App\Support;

use App\Enums\DispensationStatus;
use App\Enums\OrderStatus;
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

        return array_merge(TillSummary::breakdown($session), [
            'counted' => $session->counted_cents?->cents,
            'variance' => $session->variance_cents?->cents,
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
