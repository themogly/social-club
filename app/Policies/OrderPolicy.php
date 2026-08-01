<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use App\Support\ActiveScope;

/**
 * Bar/merch orders are recorded at the counter (App\Livewire\Counter\BarPos) and are
 * never created or edited through the panel. This policy mirrors DispensationPolicy
 * and gates two abilities:
 *
 * - `view` — the printable sale ticket. The viewer must hold the bar counter or a
 *   reporting permission, be in the SAME organisation, and either be assigned to the
 *   order's own location OR hold org-wide report rights. Object-ownership, not just
 *   authentication (NOTES §B) — the denial tests prove a wrong-location actor is blocked.
 * - `void` — reverse a completed order. Requires `order.void` (manager+) AND the same
 *   visibility check, so a manager cannot void another sede's order.
 */
class OrderPolicy
{
    /**
     * BROWSE the bar/merch order archive in the panel (OrderResource, oversight-only). Reporting only — NOT
     * `pos.bar` (prompt 122, kept in step with DispensationPolicy): running the bar takes the order in front
     * of you, it does not open every member's purchase history. Single-row reads (a bar receipt reprint) stay
     * on `view`, which keeps `pos.bar`.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('reports.view');
    }

    public function view(User $user, Order $order): bool
    {
        if (! ($user->can('pos.bar') || $user->can('reports.view'))) {
            return false;
        }

        if ($order->organisation_id !== app(ActiveScope::class)->organisationId()) {
            return false;
        }

        return $user->can('reports.view.all')
            || $user->locations()->whereKey($order->location_id)->exists();
    }

    public function void(User $user, Order $order): bool
    {
        return $user->can('order.void') && $this->view($user, $order);
    }
}
