<?php

namespace App\Livewire;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Topbar language switch (prompt 19). Persists the chosen locale to the user's OWN
 * row so it follows them across sessions and devices (null = organisation default),
 * and mirrors it into the session so SetLocale applies it on the very next request —
 * no logout/login. Only an enabled locale is honoured.
 */
class LocaleSwitcher extends Component
{
    public function switchLocale(string $locale): void
    {
        $enabled = Settings::get('enabled_locales', ['en', 'es']);

        if (is_array($enabled) && in_array($locale, $enabled, true)) {
            $user = auth()->user();
            if ($user instanceof User) {
                $user->forceFill(['locale' => $locale])->save();
            }
            session(['locale' => $locale]);
            $this->redirect(url()->previous() ?: '/', navigate: false);
        }
    }

    public function render(): View
    {
        $enabled = Settings::get('enabled_locales', ['en', 'es']);

        return view('livewire.locale-switcher', [
            'current' => app()->getLocale(),
            'locales' => is_array($enabled) ? $enabled : ['en', 'es'],
        ]);
    }
}
