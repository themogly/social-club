<?php

namespace App\Http\Middleware;

use App\Actions\Till\SelectTillSession;
use App\Models\Location;
use App\Support\CounterOperator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prompt 236 — no open till, no counter. The open-till step becomes the counter's front door.
 *
 * The owner: *"if the till isn't open, make them do it before doing anything else."* Prompt 175's chain is
 * `sede → operator → till → member`, but only the POS and the Bar ever carried the TILL step — the hub, the
 * door and Socios resolved `sede → operator` and stopped. So an operator could reach the door and record a
 * member entering a club that was not trading, or start a sign-up, with no drawer open. And where the till
 * DID block, it was a card with a link the operator had to notice and follow.
 *
 * This is the "before anything" rule, so it lives in ONE guard on the counter, not a card per screen — the
 * hub, the door, Socios, the POS, the Bar, and every screen added later are covered by construction. When the
 * sede is adopted and the operator identified (173/175's first two steps stay in-page) and the sede has no
 * open till, any counter request is sent to the till screen with the intended URL remembered; after `open()`
 * the operator lands where they were heading.
 *
 * **Appended globally**, like {@see EnforceCounterHandover} and {@see EnforceOrgLockdown}, and for the same
 * reason: it must gate before the router matches, so it matches on PATHS and `$request->route()` is null here.
 *
 * **The door reversal, said out loud.** `CounterBlocker` recorded *"Recepción has no till or member step."*
 * The owner is overriding it: entry recording without an open till means a member inside a club that is not
 * trading. A reversed decision recorded beats one silently contradicted.
 *
 * NEVER 503s the counter (prompt 124): the open-till check degrades OPEN on any error, so a cache/DB blip
 * falls through to the page's existing in-page blockers rather than to an error page.
 */
class RequireOpenTill
{
    /**
     * The session key the intended counter URL is stashed under, so `open()` can return the operator to it.
     *
     * Its own key, not Laravel's `url.intended`: the guest-redirect flow uses that one for the login round
     * trip, and a stray login must not send somebody to a counter screen.
     */
    public const INTENDED_KEY = 'counter.till_intended_url';

    /**
     * The counter paths that answer WITHOUT an open till.
     *
     * PATHS, exact where it matters (prompt 173's lesson): `counter/pos` blocks while
     * `counter/pos/receipt/{id}` is a read of an ALREADY-committed sale and does not.
     *
     *   · the till screen itself — you cannot open the till from a redirect to the till if the till redirects
     *   · the sede switcher and the operator surface (Livewire) — 173/175's first two steps run here, and
     *     the till step is by definition after them
     *   · log out — a device must always be able to leave
     *   · the panic/lockdown path — NEVER gated by anything (prompt 121): a robbery does not wait for a float
     *   · the receipts — reads of past transactions, no drawer involved
     *   · the identity-photo write — an XHR fired from a member card already on screen (i.e. mid-serve, with a
     *     till open); it must get a real response, never a 302 to an HTML page, and it is identity upkeep, not
     *     trading, so it is not what "no trading without a till" is about
     */
    private const ALLOWED_PATHS = [
        'counter/till',              // the open screen — the destination
        'counter/location',         // the sede switcher (POST)
        'counter/panic',            // the panic trigger — never gated
        'counter/members/*/photo',  // the identity-photo write (XHR, mid-serve) — never a redirect
        'reactivar/*',              // lifting a lockdown
        'counter/pos/receipt/*',    // reads of a committed contribution
        'counter/bar/receipt/*',    // reads of a committed sale
        'filament/*',               // Filament's own auth (log out) and asset routes
        'up',                       // health check
    ];

    /**
     * Is this a counter SCREEN this guard is responsible for? The panel, the socio app and the API are not.
     * ONE classifier, shared by {@see handle()} and the class guard test, so a route cannot be gated at
     * runtime but classified differently by the test that is supposed to police it.
     */
    public static function guardsPath(string $path): bool
    {
        return (Str::is('counter', $path) || Str::is('counter/*', $path)) && ! self::isAllowedPath($path);
    }

    /** Does this path answer WITHOUT an open till? The allowlist above, as a pure function of the path. */
    public static function isAllowedPath(string $path): bool
    {
        return Str::is(self::ALLOWED_PATHS, $path);
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Only counter SCREENS are gated. Everything else (the panel, the socio app, the API) is none of this
        // middleware's business, and an early return keeps the cost off every non-counter request.
        if (! self::guardsPath($request->decodedPath())) {
            return $next($request);
        }

        // The first two steps of 175's chain are still in-page (173's surface). This guard is ONLY the third:
        // it acts once a sede is adopted and an operator identified, and otherwise defers to the page, which
        // shows the sede chooser or the PIN surface exactly as before.
        $locationId = session('counter.location_id');

        if ($locationId === null || CounterOperator::id() === null) {
            return $next($request);
        }

        if ($this->sedeHasOpenTill((string) $locationId)) {
            return $next($request);
        }

        // Remember where they were going, so `open()` can send them back. A GET only — a POST is a write, and
        // replaying it after the till opens is not what "continue" means.
        if ($request->isMethod('GET')) {
            session([self::INTENDED_KEY => $request->fullUrl()]);
        }

        return redirect()->route('counter.till');
    }

    /**
     * Does this sede have an open till? Degrades OPEN on any failure (prompt 124): a blip here must fall
     * through to the page's own blockers, never redirect-loop or 503 the counter.
     */
    private function sedeHasOpenTill(string $locationId): bool
    {
        try {
            $location = Location::query()->withoutGlobalScopes()->find($locationId);

            if ($location === null) {
                return true; // an unresolvable sede is the sede step's problem, not this one
            }

            return (new SelectTillSession)->openAt($location)->isNotEmpty();
        } catch (\Throwable) {
            return true;
        }
    }
}
