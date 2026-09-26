<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A counter-only account (no `panel.access`, prompt 262) that asks for a panel URL is sent to the counter — not shown
 * Filament's 403, and not logged out. On the panel's own middleware stack, after the session starts and before
 * Filament's Authenticate. The panel's sign-in and sign-out routes pass through: the counter's "Salir" posts to the
 * panel's logout.
 */
class RedirectCounterOnlyAccounts
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->canUseTheApp() && ! $user->can('panel.access')
            && ! $request->routeIs('filament.admin.auth.*')) {
            return redirect()->route('counter.home');
        }

        return $next($request);
    }
}
