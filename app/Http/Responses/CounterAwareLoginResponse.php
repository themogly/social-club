<?php

namespace App\Http\Responses;

use App\Models\User;
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
    public function toResponse($request): RedirectResponse|Redirector
    {
        $user = $request->user();

        if ($user instanceof User && $user->can('panel.access')) {
            return redirect()->intended(Filament::getUrl());
        }

        $intended = (string) session()->pull('url.intended', '');
        $path = ltrim((string) parse_url($intended, PHP_URL_PATH), '/');

        return redirect()->to(Str::is(['counter', 'counter/*'], $path) ? $intended : route('counter.home'));
    }
}
