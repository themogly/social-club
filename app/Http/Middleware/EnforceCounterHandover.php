<?php

namespace App\Http\Middleware;

use App\Support\CounterHandover;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
     * These are PATHS, not route names. Since prompt 241 this middleware runs on the web group AND on the
     * Filament panel's own stack, both AFTER StartSession — not on the global stack, where it read a null
     * session and served the applicant the dashboard (the Article-9 leak 241 closed). The list stays paths
     * because ONE list serves both stacks and a path check is identical on each, not because the router has
     * not run. {@see EnforceOrgLockdown} matches on paths the same way. Getting this wrong is not subtle — a
     * miss redirects a counter screen to the counter and the tablet spins in a loop.
     *
     * The counter SCREEN entries are EXACT (no trailing wildcard) and that is load-bearing: `counter/pos` must
     * answer while `counter/pos/receipt/{id}` must not, and likewise `counter/members` against
     * `counter/members/{member}/photo`. `counter` (189's front door, added AFTER the first five and forgotten)
     * belongs here too: `counter-home.blade.php` renders only the handover surface, so it is safe, and leaving
     * it out is exactly what stranded the tablet — the ONE screen most likely to be typed redirected instead
     * of answering. `CounterLayoutRoutesAreAllowedTest` now enumerates every counter-layout route from the
     * route table so a seventh screen cannot be forgotten the way the sixth was.
     */
    private const ALLOWED_PATHS = [
        'counter',             // the front door (prompt 189) — renders only the surface during a handover
        'counter/checkin',     // the five working screens — during a handover each renders only the surface
        'counter/members',
        'counter/till',
        'counter/pos',
        'counter/bar',
        'socio/solicitud/*',   // the form the applicant was handed, its submit, and 179's MRZ read
        'socio/idioma',        // prompt 167 — choosing a language is not a member-only act
        'livewire/*',          // the PIN pad posts here; it is the only way the handover ends
        'up',                  // health check
    ];

    /**
     * Does a path answer during a handover? The middleware's own test AND {@see CounterLayoutRoutesAreAllowedTest}
     * read the allowlist through here rather than the private const, so the guard and its structural test agree
     * on one matcher.
     */
    public static function allows(string $path): bool
    {
        return Str::is(self::ALLOWED_PATHS, ltrim($path, '/'));
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! CounterHandover::active()) {
            return $next($request);
        }

        if (self::allows($request->path())) {
            return $next($request);
        }

        // Back to where the applicant belongs. Once the form has been submitted returnUrl() is null (prompt
        // 249), so this falls through to the counter — safe, because during a handover every counter screen
        // renders the surface, never the counter, so a stray request can only land on the PIN pad.
        return redirect()->to(CounterHandover::returnUrl() ?? route('counter.checkin'));
    }
}
