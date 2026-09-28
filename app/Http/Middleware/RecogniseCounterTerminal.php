<?php

namespace App\Http\Middleware;

use App\Support\CounterOperator;
use App\Support\CounterTerminals;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A registered counter (prompt 289) opens on the PIN pad, never on a password.
 *
 * Reads the `csc_terminal` cookie ({@see CounterTerminals}); runs on the web group AND the panel's own stack, AFTER
 * StartSession (it reads and writes the session — the 241 lesson). With a valid terminal and nobody signed in:
 * `/` and `/login` go to the counter (whose lock surface asks for a PIN), and the counter's sede and organisation come
 * from the terminal. `/login?password=1` — "Entrar con contraseña" on the lock surface — still reaches the password form.
 * An invalid cookie is cleared and ignored. Once an hour it stamps `last_seen_at` and re-issues the cookie.
 *
 * It authorises nothing: every counter action is still refused without a PIN operator (255). Degrades to "an ordinary
 * browser" if anything here fails — never a 500 at the door (124).
 */
class RecogniseCounterTerminal
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $terminal = CounterTerminals::current($request);
        } catch (Throwable) {
            return $next($request);
        }

        // Post-296 audit, finding 5 — a person signed in by PIN on a tablet stays signed in only while THAT tablet is
        // registered: revoking a stolen tablet signs out whoever was on it, from its very next request.
        $signedInOn = session('counter.terminal_id');
        if (is_string($signedInOn) && $signedInOn !== $terminal?->id && Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
            CounterOperator::clear();
            session()->forget(['counter.terminal_id', 'auth.via_pin']);
            session()->regenerate();
        }

        if ($terminal === null) {
            if (CounterTerminals::presented($request)) {
                CounterTerminals::forget();
            }

            return $next($request);
        }

        if ($terminal->last_seen_at === null || $terminal->last_seen_at->lt(now()->subHour())) {
            $terminal->forceFill(['last_seen_at' => now()])->saveQuietly();
            CounterTerminals::renew($request);
        }

        if (! Auth::guard('web')->check()) {
            if (! is_string(session('scope.organisation_id'))) {
                session(['scope.organisation_id' => $terminal->organisation_id]);
            }
            if (! is_string(session('counter.location_id'))) {
                session(['counter.location_id' => $terminal->location_id]);
            }

            if ($request->isMethod('GET') && ($request->is('/') || $request->is('login')) && ! $request->has('password')) {
                return redirect()->route('counter.home');
            }
        }

        return $next($request);
    }
}
