<?php

namespace App\Http\Middleware;

use App\Support\CounterHandover;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prompt 173's handover mode, enforced as a SERVER-SIDE boundary (security audit, Phase C carry-forward).
 *
 * 173 blanked the five counter screens while an applicant holds the tablet. What it never did was constrain
 * the SESSION behind them: the device `User` stays authenticated with panel access throughout, so leaving the
 * counter URL — the address bar, the back button, a bookmark — reached the full Filament panel. Measured
 * before this middleware existed: `GET /` returned 200 with the dashboard, and the member list returned 200
 * with a member's surname in the HTML. The screens were a picture of a gate.
 *
 * So the mode is now an allowlist, appended globally like {@see EnforceOrgLockdown}. While a handover is
 * active only four things answer: the tokenised application form the applicant was handed, the language
 * switcher that sits on it (prompt 167), the five counter SCREENS — which render nothing but the handover
 * surface and its PIN pad — and Livewire's own endpoint, because the PIN pad is how the handover ENDS and
 * blocking it would strand the tablet.
 *
 * Everything else is redirected back to where the applicant belongs. Deliberately NOT the counter receipts,
 * the photo-capture POST, the sede switcher or the panic POST: all of those live in the top bar, which is
 * absent from the DOM during a handover, so nothing legitimate calls them and an applicant reaching one is
 * by definition not legitimate.
 */
class EnforceCounterHandover
{
    /**
     * The only paths that answer during a handover.
     *
     * These are PATHS, not route names, because this is GLOBAL middleware: it runs before the router has
     * matched anything, so `$request->route()` is still null here. {@see EnforceOrgLockdown} matches on paths
     * for the same reason. Getting this wrong is not subtle — a route-name check matches nothing, so every
     * counter screen redirects to the counter and the tablet spins in a loop.
     *
     * The five counter entries are EXACT (no trailing wildcard) and that is load-bearing: `counter/pos` must
     * answer while `counter/pos/receipt/{id}` must not, and likewise `counter/members` against
     * `counter/members/{member}/photo`.
     */
    private const ALLOWED_PATHS = [
        'counter/checkin',     // the five counter SCREENS — during a handover each renders only the surface
        'counter/members',
        'counter/till',
        'counter/pos',
        'counter/bar',
        'socio/solicitud/*',   // the form the applicant was handed, its submit, and 179's MRZ read
        'socio/idioma',        // prompt 167 — choosing a language is not a member-only act
        'livewire/*',          // the PIN pad posts here; it is the only way the handover ends
        'up',                  // health check
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! CounterHandover::active()) {
            return $next($request);
        }

        if ($request->is(...self::ALLOWED_PATHS)) {
            return $next($request);
        }

        // Back to the form they were handed. Falling back to the counter is safe: during a handover every
        // counter screen renders the surface, never the counter, so this can only land on the PIN pad.
        return redirect()->to(CounterHandover::returnUrl() ?? route('counter.checkin'));
    }
}
