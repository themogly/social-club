<?php

namespace App\Http\Controllers;

use App\Models\Dispensation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * Renders a single dispensation's printable ticket, worded as a shared-cost
 * CONTRIBUTION (aportación). Resolved by ULID (never a sequential id) and
 * authorization-checked through DispensationPolicy — the viewer must be able to see
 * this dispensation (right permission, same organisation, and either the row's own
 * location or org-wide report rights). Global scopes are lifted for the lookup so a
 * just-committed ticket resolves regardless of the active-location switch; the policy
 * is the real gate, not the scope.
 */
class DispensationReceiptController extends Controller
{
    public function show(string $dispensation): View
    {
        $model = Dispensation::query()->withoutGlobalScopes()->findOrFail($dispensation);

        Gate::authorize('view', $model);

        $model->load(['lines', 'member', 'location', 'organisation']);

        return view('receipts.receipt', ['dispensation' => $model]);
    }
}
