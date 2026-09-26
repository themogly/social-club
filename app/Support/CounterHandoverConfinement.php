<?php

namespace App\Support;

use App\Actions\RecordAuditLog;
use App\Http\Middleware\EnforceCounterHandover;
use App\Livewire\Counter\Concerns\IdentifiesOperator;
use App\Livewire\Counter\CounterChrome;
use App\Models\Location;
use Livewire\Component;

use function Livewire\before;

/**
 * While an applicant holds the tablet, a Livewire request may do exactly what the handover surface does, and
 * nothing else (prompt 254).
 *
 * {@see EnforceCounterHandover} lets Livewire's update endpoint through during a handover, because the PIN pad
 * posts there and it is the only way the handover ends. But a path is not a call: the same endpoint carries
 * every public method of every component on the page. With the path allowed and nothing else, the applicant's
 * own page could run `$wire.lookupResults()` and receive full member rows (DNI, address, therapeutic flag), read
 * other applicants' pending forms, or close the till. The handover is a SERVER-side boundary (173/241), so the
 * calls are confined here, server-side, and the surface's markup is irrelevant to it.
 *
 * A GLOBAL Livewire hook, not a per-component one in {@see IdentifiesOperator}, deliberately: the trait hook
 * would only see the counter screens, while the page also carries {@see CounterChrome} (which listens for the
 * lock) and a panel component's snapshot taken before the handover can be replayed at the same endpoint. Only a
 * global hook sees every component; one list below says what answers.
 *
 * What answers during a handover (read from `counter-surface.blade.php`'s `submit()` and the counter layout's
 * idle timer):
 *   · a counter screen (any component composing IdentifiesOperator): a bare re-render; the `operatorPin` update;
 *     the `unlockOperator()` call; and the idle timer's `counter-lock` event, which reaches `lockCounter()` and
 *     ends the handover as timed out;
 *   · the chrome: the `counter-lock` / `counter-unlocked` events that re-render it (both empty-bodied);
 *   · every other component: nothing — not even a bare re-render.
 *
 * Anything else is refused with a 403 BEFORE it runs — no side effect, no data in the response — and audit-logged
 * as `counter.handover.refused_call`, naming the component and the property/method, never the payload. Hooks run
 * per call in order, so a request that ends the handover with the right PIN and then does counter work is
 * judged by the state at each step: after the PIN, the tablet is the counter's again. Outside a handover this
 * does nothing at all.
 */
class CounterHandoverConfinement
{
    /** The PIN pad's one bound field. */
    private const SURFACE_UPDATES = ['operatorPin'];

    /** The PIN pad's submit. */
    private const SURFACE_CALLS = ['unlockOperator'];

    /** Events a counter screen may receive: the idle timer's lock. */
    private const SURFACE_EVENTS = ['counter-lock'];

    /** Events the chrome may receive: the two that change whether it renders (both empty-bodied handlers). */
    private const CHROME_EVENTS = ['counter-lock', 'counter-unlocked'];

    /**
     * Registered on Livewire's BEFORE tier, not `Livewire::listen()`. Order is the point: Livewire's own
     * `SupportEvents` resolves a `__dispatch` INSIDE its call hook and runs the handler there, so an ordinary
     * listener registered after it would refuse an event whose handler had already run. `before` hooks run ahead
     * of every feature hook, so a refusal is judged before anything executes.
     */
    public static function register(): void
    {
        before('hydrate', function (Component $component): void {
            if (CounterHandover::active() && ! self::isScreen($component) && ! $component instanceof CounterChrome) {
                self::refuse($component, 'hydrate');
            }
        });

        before('update', function (Component $component, string $path): void {
            if (CounterHandover::active() && ! (self::isScreen($component) && in_array($path, self::SURFACE_UPDATES, true))) {
                self::refuse($component, 'update:'.strtok($path, '.'));
            }
        });

        before('call', function (Component $component, string $method, array $params): void {
            if (CounterHandover::active() && ! self::allowsCall($component, $method, $params)) {
                self::refuse($component, $method === '__dispatch' ? '__dispatch:'.self::eventName($params) : $method);
            }
        });
    }

    /** A counter screen — every one composes the PIN pad's trait, so a new screen is covered by construction. */
    private static function isScreen(Component $component): bool
    {
        return in_array(IdentifiesOperator::class, class_uses_recursive($component), true);
    }

    /** @param  array<int, mixed>  $params */
    private static function allowsCall(Component $component, string $method, array $params): bool
    {
        if ($method === '__dispatch') {
            $events = match (true) {
                self::isScreen($component) => self::SURFACE_EVENTS,
                $component instanceof CounterChrome => self::CHROME_EVENTS,
                default => [],
            };

            return in_array(self::eventName($params), $events, true);
        }

        return self::isScreen($component) && in_array($method, self::SURFACE_CALLS, true);
    }

    /** @param  array<int, mixed>  $params */
    private static function eventName(array $params): string
    {
        return is_string($params[0] ?? null) ? $params[0] : '';
    }

    private static function refuse(Component $component, string $what): never
    {
        $locationId = CounterHandover::current()['location_id'] ?? null;

        (new RecordAuditLog)->handle(
            'counter.handover.refused_call',
            $locationId === null ? null : Location::withoutGlobalScopes()->find($locationId),
            after: ['component' => $component->getName(), 'method' => $what],
        );

        abort(403);
    }
}
