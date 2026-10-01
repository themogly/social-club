<?php

namespace App\Http\Responses;

use App\Actions\RecordAuditLog;
use App\Models\User;
use App\Support\CounterHandover;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * Where a successful sign-in lands (prompt 262). With `panel.access` → the panel, exactly as Filament did. Without it
 * (a counter-only account) → straight to the counter: the URL the account was sent to login from, when that was a
 * counter URL, otherwise the counter's front door. Never the panel, which would only bounce it back.
 */
class CounterAwareLoginResponse implements LoginResponse
{
    /** Prompt 342 — routes that are only ever an applicant's: never a destination after a staff login. */
    private const APPLICANT_ONLY = ['socio/solicitud/*'];

    public function toResponse($request): RedirectResponse|Redirector
    {
        $user = $request->user();

        // Prompt 342 — whoever just proved they are staff with a password is not an applicant: any handover in this
        // session ends (a tablet handed over, closed and reopened used to land back on the form, with no way out).
        if (CounterHandover::active()) {
            $state = (array) CounterHandover::current();
            CounterHandover::end();
            (new RecordAuditLog)->handle('counter.handover.cancelled', null, null, [
                'reason' => 'login', 'operator_id' => $user?->getKey(), 'location_id' => $state['location_id'] ?? null,
            ]);
        }

        // …and a sign-in never lands on an applicant-only route (the form), whatever was "intended".
        $intended = (string) session('url.intended', '');
        $path = ltrim((string) parse_url($intended, PHP_URL_PATH), '/');
        if (Str::is(self::APPLICANT_ONLY, $path)) {
            session()->forget('url.intended');
            $intended = '';
            $path = '';
        }

        if ($user instanceof User && $user->can('panel.access')) {
            return redirect()->intended(Filament::getUrl());
        }

        session()->forget('url.intended');

        return redirect()->to(Str::is(['counter', 'counter/*'], $path) ? $intended : route('counter.home'));
    }
}
