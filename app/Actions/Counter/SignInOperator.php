<?php

namespace App\Actions\Counter;

use App\Actions\RecordAuditLog;
use App\Models\Location;
use App\Models\User;
use App\Support\CounterOperator;
use App\Support\CounterTerminals;
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
 *
 * **Naming the operator and signing in are ONE step (prompt 270).** The till handover set the counter's operator
 * without signing in, so after owner → staff the staff member worked under the owner's login and `/users` answered 200.
 * This action now sets `CounterOperator` itself, and it is the only production caller of `CounterOperator::set()`, so
 * a third path cannot split the two again (`CounterOperatorIsASignInTest` pins that).
 */
class SignInOperator
{
    public function handle(User $operator, ?Location $location): void
    {
        $from = Auth::id();
        $guard = Auth::guard('web');
        // A PIN session is one the PIN OPENED: nobody signed in, someone else, or already a PIN session. The same person
        // typing their PIN in a session they opened with their password has already given it (297's harness found this).
        $viaPin = $from === null || (string) $from !== (string) $operator->getKey() || session('auth.via_pin') === true;

        CounterOperator::set($operator);
        $guard->login($operator);
        if ($guard instanceof SessionGuard) {
            Cookie::queue(Cookie::forget($guard->getRecallerName()));
        }
        session()->forget('scope.location_id');
        // Post-296 audit — remember HOW and WHERE this person signed in: by PIN (so the panel asks for their password once
        // a shift, finding 7) and on which registered tablet (so revoking it signs them out, finding 5).
        session(['auth.via_pin' => $viaPin, 'counter.terminal_id' => CounterTerminals::current()?->id]);

        (new RecordAuditLog)->handle('counter.operator.signed_in', $location, null, [
            'location_id' => $location?->id,
            'from_user_id' => $from === null ? null : (string) $from,
            'to_user_id' => $operator->id,
        ]);
    }
}
