<?php

namespace App\Actions\Counter;

use App\Actions\RecordAuditLog;
use App\Models\Location;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

/**
 * The PIN IS a sign-in (prompt 267, Ben's Option B). A successful PIN unlock signs the browser session in as that
 * person, so the counter, the admin panel and the audit trail are ONE identity.
 *
 * Before this the counter acted on the PIN person (255) while the admin panel acted on the tablet's login: on an
 * owner-logged tablet with a STAFF PIN at the counter, `/users`, `/roles-y-permisos` and `/audit-logs` all answered as
 * the owner — a staff member could grant themselves anything, recorded as the owner.
 *
 *   · `Auth::login()` migrates the session: a NEW id (no fixation) with its data kept, so the chosen sede, the basket
 *     and any handover state carry over untouched; it also refreshes the password hash `AuthenticateSession` checks.
 *   · The original account's REMEMBER-ME cookie is cleared — left in place it would silently sign the tablet's first
 *     account back in when the session expired.
 *   · The panel's own sede selection is dropped, so the panel re-resolves for THIS person's sedes; the counter stays on
 *     its own `counter.location_id`, which a staff PIN still cannot change (246).
 *   · The switch itself is audited (`counter.operator.signed_in`: who, the sede, from which account); everything after it
 *     is already the operator's (255/261).
 *
 * The PIN check itself — `UnlockOperator`, its per-sede staff list and its throttle — is unchanged and runs before this.
 */
class SignInOperator
{
    public function handle(User $operator, ?Location $location): void
    {
        $from = Auth::id();
        $guard = Auth::guard('web');

        $guard->login($operator);
        if ($guard instanceof SessionGuard) {
            Cookie::queue(Cookie::forget($guard->getRecallerName()));
        }
        session()->forget('scope.location_id');

        (new RecordAuditLog)->handle('counter.operator.signed_in', $location, null, [
            'location_id' => $location?->id,
            'from_user_id' => $from === null ? null : (string) $from,
            'to_user_id' => $operator->id,
        ]);
    }
}
