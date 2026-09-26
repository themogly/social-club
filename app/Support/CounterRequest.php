<?php

namespace App\Support;

use App\Actions\RecordAuditLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

use function Livewire\before;

/**
 * Is THIS request counter work — and so, who is its actor? (prompt 261)
 *
 * 255 made the PIN operator the actor for every counter permission check and `*_by` column. The audit log still
 * wrote `Auth::id()` — the tablet's login — so a till closed by a manager read `closed_by = manager` on the record
 * and "the owner" in the audit trail. {@see RecordAuditLog} now asks here.
 *
 * NOT "is an operator in the session". `CounterOperator` lives in the session, and the same browser session can
 * open the admin panel: an owner who opens the panel on a tablet after a staff member typed their PIN would have
 * their PANEL actions attributed to the staff member — a new misattribution, worse than the one being fixed. So a
 * request is counter work only when it is one of:
 *   · a `counter` / `counter/*` route (the screens, the photo capture, the sede switch, the panic button); or
 *   · a Livewire update in which a counter component (`App\Livewire\Counter\*`) hydrated — marked as a REQUEST
 *     attribute by the hook below, so it dies with the request and cannot leak into the next one.
 * Queued jobs and console commands have neither, so they keep a null actor.
 */
class CounterRequest
{
    private const ATTRIBUTE = 'counter.request';

    public static function register(): void
    {
        before('hydrate', function (Component $component): void {
            if (str_starts_with($component::class, 'App\\Livewire\\Counter\\')) {
                request()->attributes->set(self::ATTRIBUTE, true);
            }
        });
    }

    public static function is(): bool
    {
        $request = request();

        return $request->attributes->getBoolean(self::ATTRIBUTE) || $request->is('counter', 'counter/*');
    }

    /**
     * The actor to attribute: on a counter request the PIN operator — falling back to the tablet's login only when
     * nobody is identified (the sede switch, the panic button, which are legitimately pre-PIN); elsewhere the
     * logged-in user, exactly as before.
     */
    public static function actorId(): ?string
    {
        $userId = Auth::id();
        $loggedIn = $userId === null ? null : (string) $userId;

        return self::is() ? (CounterOperator::id() ?? $loggedIn) : $loggedIn;
    }
}
