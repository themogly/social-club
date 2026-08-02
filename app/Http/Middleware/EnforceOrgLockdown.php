<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\OrganisationLockdown;
use App\Models\User;
use App\Support\ActiveScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prompt 121 — the org-wide panic-lockdown gate. Appended globally (like SecurityHeaders) so it reaches the
 * admin panel, the counter and the member PWA in one place. When the org is locked down it returns an
 * ORDINARY-looking "temporarily unavailable" page — NEVER a "SITE LOCKED" banner: telling whoever is in the room
 * that they have been thwarted, while they are still in the room with your staff, is the dangerous outcome. A
 * mundane outage reads as a glitch.
 *
 * Two things are always let through so nobody is permanently bricked: the reactivation surface (`/seguridad/*`,
 * where the owner's emailed link lands — the only in-app way back), and — for a DRILL only — an authenticated
 * owner, so the club can observe and end the rehearsal from the panel. A REAL lockdown lets no one back in here;
 * it lifts only via the owner's off-terminal link, the time-delay, or the break-glass CLI.
 */
class EnforceOrgLockdown
{
    public function handle(Request $request, Closure $next): Response
    {
        // The token reactivation link is always reachable — it is the owner's off-terminal way back. It is a
        // plain web route (not a panel page), so this narrow path can never be a lockable surface itself.
        if ($request->is('reactivar/*')) {
            return $next($request);
        }

        // Resolve the org and its lockdown state, degrading OPEN on any error (missing table pre-migration, a DB
        // blip): a lockdown is a rare state and the app cannot serve anything without a DB anyway, so blocking
        // on a DB fault would be worse than the rare miss — the same philosophy as Settings::get (prompt 124).
        try {
            $organisationId = app(ActiveScope::class)->organisationId();
            $lockdown = $organisationId !== null ? OrganisationLockdown::active($organisationId) : null;
        } catch (\Throwable) {
            return $next($request);
        }

        if ($lockdown === null) {
            return $next($request);
        }

        // A drill lets an owner through to observe + end it; everyone else sees exactly what a real lockdown
        // would show them, which is the point of rehearsing.
        if ($lockdown->is_drill) {
            $user = $request->user();
            if ($user instanceof User && $user->hasRole(Role::OWNER->value)) {
                return $next($request);
            }
        }

        return response()->view('errors.unavailable', [], Response::HTTP_SERVICE_UNAVAILABLE)
            ->header('Retry-After', '600');
    }
}
