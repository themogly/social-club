<?php

namespace App\Support;

use App\Actions\RecordAuditLog;
use App\Http\Middleware\RedirectCounterOnlyAccounts;
use App\Models\Location;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

use function Livewire\before;

/**
 * "Locked means locked" for Livewire too (prompt 270).
 *
 * 267 made a locked counter session (it has adopted a sede, nobody is identified at the PIN) lose the admin panel —
 * but only for page loads: {@see RedirectCounterOnlyAccounts} sits on the panel's route stack, and Livewire's update
 * endpoint re-runs only the persistent middleware. The session is still signed in as the last PIN person, so a panel
 * page's snapshot replayed from the tablet's history (the back gesture, bfcache) ran as them — the audit toggled a
 * permission on Roles y permisos as the owner, from a locked tablet.
 *
 * A GLOBAL `before('hydrate')` hook, the shape of {@see CounterHandoverConfinement}: while the counter is locked, only
 * the counter's own components answer (their PIN pad is how the lock ends) and the panel's sign-in pages (so somebody
 * can still log in properly). Anything else is refused with a 403 before it runs, and audited.
 */
class CounterLockConfinement
{
    public static function register(): void
    {
        before('hydrate', function (Component $component): void {
            if (self::locked() && ! self::answersWhileLocked($component)) {
                $locationId = session('counter.location_id');

                (new RecordAuditLog)->handle(
                    'counter.lock.refused_call',
                    is_string($locationId) ? Location::withoutGlobalScopes()->find($locationId) : null,
                    after: ['component' => $component->getName()],
                );

                abort(403);
            }
        });
    }

    /** A counter session, signed in, with nobody at the PIN — the state 267 calls locked. */
    private static function locked(): bool
    {
        return Auth::guard('web')->check()
            && is_string(session('counter.location_id'))
            && CounterOperator::id() === null;
    }

    private static function answersWhileLocked(Component $component): bool
    {
        return str_starts_with($component::class, 'App\\Livewire\\Counter\\')
            || str_starts_with($component::class, 'App\\Filament\\Pages\\Auth\\')
            || str_starts_with($component::class, 'Filament\\Auth\\');
    }
}
