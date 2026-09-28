<?php

namespace App\Http\Middleware;

use App\Support\CounterTerminals;
use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The counter's routes accept a signed-in person OR a registered counter (prompt 289) — otherwise exactly Laravel's
 * `auth`. A terminal with nobody signed in reaches the screens only to show their lock surface; the screens' own gates
 * and every action still need the PIN operator. Registered as Livewire persistent middleware, so a screen's updates are
 * held to the same rule as its page.
 */
class AuthenticateCounter
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('web')->check() || CounterTerminals::current($request) !== null) {
            return $next($request);
        }

        return app(Authenticate::class)->handle($request, $next);
    }
}
