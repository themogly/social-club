<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\CounterOperator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who is sent back to the counter from a panel URL — not shown Filament's 403, and not logged out: a counter-only account
 * (no `panel.access`, prompt 262), and a counter session with nobody identified at the PIN (prompt 267). On the panel's own middleware stack, after the session starts and before
 * Filament's Authenticate. The panel's sign-in and sign-out routes pass through: the counter's "Salir" posts to the
 * panel's logout.
 */
class RedirectCounterOnlyAccounts
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $request->routeIs('filament.admin.auth.*')) {
            return $next($request);
        }

        // Prompt 262 — a counter-only account (no panel.access).
        if ($user->canUseTheApp() && ! $user->can('panel.access')) {
            return redirect()->route('counter.home');
        }

        // Prompt 267 — LOCKED MEANS LOCKED. A counter session (it has adopted a sede) with nobody identified at the PIN —
        // after the idle lock, "Cambiar de persona", or before the first PIN — has no admin panel either: walking away
        // from a locked counter must not leave a live panel behind it. The PIN (which signs the session in as its
        // person) reopens it. A password login that never used the counter (the owner on their own phone) is untouched.
        if (is_string($request->session()->get('counter.location_id')) && CounterOperator::id() === null) {
            return redirect()->route('counter.home');
        }

        return $next($request);
    }
}
