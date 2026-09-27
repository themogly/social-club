<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\CounterOperator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deactivating an account takes effect on the NEXT request (prompt 270).
 *
 * "Deactivate" is how a club removes someone who has just been let go. The panel already refused them
 * (`canAccessPanel()` checks `active`), but the counter only asked whether a session was signed in and an operator id
 * was present — a deactivated manager kept `/counter/till` and could still close the drawer until the idle lock. Since
 * 267 that session IS them, so it is signed out here; an operator id naming an inactive or deleted account is dropped.
 * On the web group, after StartSession.
 */
class EndInactiveSessions
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if ($user instanceof User && ! $user->active) {
            CounterOperator::clear();
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson() ? abort(401) : redirect('/login');
        }

        $operatorId = CounterOperator::id();
        if ($operatorId !== null && $operatorId !== $user?->getAuthIdentifier()
            && ! User::query()->whereKey($operatorId)->where('active', true)->exists()) {
            CounterOperator::clear();
        }

        return $next($request);
    }
}
