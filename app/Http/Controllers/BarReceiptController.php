<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * Renders a single bar/merch order's printable ticket, worded as a NORMAL SALE
 * (venta / ticket / importe) — deliberately distinct from the cannabis contribution
 * vocabulary (aportación / dispensación), because this genuinely is a sale of
 * refreshments/merch on a separate ledger. Resolved by ULID (never a sequential id)
 * and authorization-checked through OrderPolicy — the viewer must be able to see this
 * order (right permission, same organisation, own location or org-wide report rights).
 * Global scopes are lifted for the lookup so a just-committed ticket resolves regardless
 * of the active-location switch; the policy is the real gate, not the scope.
 */
class BarReceiptController extends Controller
{
    public function show(string $order): View
    {
        $model = Order::query()->withoutGlobalScopes()->findOrFail($order);

        Gate::authorize('view', $model);

        $model->load(['member', 'location', 'organisation']);

        return view('receipts.bar-receipt', ['order' => $model]);
    }
}
