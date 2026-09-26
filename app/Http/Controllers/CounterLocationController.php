<?php

namespace App\Http\Controllers;

use App\Enums\TillSessionStatus;
use App\Models\TillSession;
use App\Support\CounterOperator;
use App\Support\LocationSwitcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Switching the counter's working sede (prompt 89). The counter keeps its OWN location state
 * (session `counter.location_id`) separate from the admin panel's scope — this is the only place it is
 * written, and only through LocationSwitcher's server-side assignment check (never a raw setLocation from
 * client input). A full-page POST + redirect back: the counter screen re-mounts on the new sede, which is
 * why the caller confirms first when there is unsaved counter work.
 */
class CounterLocationController extends Controller
{
    public function switch(Request $request, LocationSwitcher $switcher): RedirectResponse
    {
        $user = $request->user();
        $locationId = trim((string) $request->input('location_id'));

        // Server-side validation: only an assigned, active sede. The counter cannot operate across sedes,
        // so "All locations" (null) is not a valid target — a specific, permitted sede or nothing.
        if ($user === null || $locationId === '' || ! $switcher->available($user)->contains(fn ($l): bool => $l->id === $locationId)) {
            return back()->with('counterLocationError', __('No puedes trabajar en esa sede.'));
        }

        $current = session('counter.location_id');
        $isChange = is_string($current) && $current !== '' && $current !== $locationId;

        // Prompt 246 — CHANGING an already-adopted sede is the OPERATOR's act, gated on the PIN-identified
        // person (settings.manage.location — MANAGER+, held by no STAFF), not the device account: an owner-logged
        // tablet must not let a STAFF operator move the whole terminal by a PIN-less tap. The INITIAL adoption
        // (no current sede — the sede→operator chain's first step, before any operator is identified) is open,
        // or a multi-sede STAFF could never start. The gate is not a picture: the top-bar shows a static badge
        // for a non-manager, and this refuses the crafted POST behind it.
        if ($isChange) {
            $operator = CounterOperator::current();
            if ($operator === null || ! $operator->can('settings.manage.location')) {
                return back()->with('counterLocationError', __('La sede la cambia un responsable'));
            }

            // The till is the thing most likely to end up mis-scoped: refuse leaving a sede whose till is still
            // open (close the blind arqueo first).
            if ($this->tillOpenAt($current)) {
                return back()->with('counterLocationError', __('Cierra la caja de esta sede antes de cambiar.'));
            }
        }

        session(['counter.location_id' => $locationId]);

        return back();
    }

    private function tillOpenAt(string $locationId): bool
    {
        return TillSession::query()->withoutGlobalScopes()
            ->where('location_id', $locationId)
            ->where('status', TillSessionStatus::OPEN->value)
            ->exists();
    }
}
