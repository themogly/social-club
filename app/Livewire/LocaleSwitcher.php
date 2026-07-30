<?php

namespace App\Livewire;

use App\Support\Settings;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Topbar language switch. Persists the chosen locale in the session (SetLocale applies it). */
class LocaleSwitcher extends Component
{
    public function switchLocale(string $locale): void
    {
        $enabled = Settings::get('enabled_locales', ['es', 'en']);

        if (is_array($enabled) && in_array($locale, $enabled, true)) {
            session(['locale' => $locale]);
            $this->redirect('/', navigate: false);
        }
    }

    public function render(): View
    {
        $enabled = Settings::get('enabled_locales', ['es', 'en']);

        return view('livewire.locale-switcher', [
            'current' => app()->getLocale(),
            'locales' => is_array($enabled) ? $enabled : ['es', 'en'],
        ]);
    }
}
