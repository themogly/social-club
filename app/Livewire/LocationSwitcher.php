<?php

namespace App\Livewire;

use App\Support\LocationSwitcher as Switcher;
use App\Support\PanelReturnUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Topbar location switcher. Lists the user's assigned locations (OWNER also "All
 * locations"); selecting one sets the active location (LocationScope) for the
 * session and reloads THE SAME PAGE (prompt 284). Server-validated against the user's assignments.
 */
class LocationSwitcher extends Component
{
    public ?string $active = null;

    /**
     * Prompt 284 — the page this switcher was rendered on, captured at mount (the real page request). Inside
     * switchTo() `request()` is Livewire's update endpoint, so it cannot be read there. Locked: the client cannot
     * point the redirect elsewhere, and PanelReturnUrl checks it anyway.
     */
    #[Locked]
    public string $returnUrl = '/';

    public function mount(): void
    {
        $this->returnUrl = request()->getSchemeAndHttpHost().request()->getRequestUri();

        $switcher = app(Switcher::class);
        $user = Auth::user();

        // A one-sede user (or a manager with one assigned sede) has no choice to make: default the session to
        // that single sede so the topbar NAMES it and scoping matches, instead of an ambiguous rollup of one
        // (prompt 148). A genuine multi-sede owner still defaults to the rollup (defaultLocationId → null).
        if ($user !== null && $switcher->current() === null) {
            $default = $switcher->defaultLocationId($user);
            if ($default !== null) {
                $switcher->switch($user, $default);
            }
        }

        $this->active = $switcher->current();
    }

    /** `$currentUrl` is the browser's address, honoured only as the SAME page's query string (PanelReturnUrl::samePage). */
    public function switchTo(?string $locationId, ?string $currentUrl = null): void
    {
        $user = Auth::user();
        $target = ($locationId === '' || $locationId === null) ? null : $locationId;

        // Still a FULL reload, so every scoped query, table and widget re-resolves under the new scope — only the
        // destination changed: the same page (its query string keeps the table's search, filters and tab).
        if ($user !== null && app(Switcher::class)->switch($user, $target)) {
            $this->redirect(PanelReturnUrl::after(PanelReturnUrl::samePage($this->returnUrl, $currentUrl)), navigate: false);
        }
    }

    public function render(): View
    {
        $switcher = app(Switcher::class);
        $user = Auth::user();

        return view('livewire.location-switcher', [
            'locations' => $user !== null ? $switcher->available($user, includeStores: true) : collect(), // the panel shows the store (277)
            'canSwitchToAll' => $user !== null && $switcher->canSwitchToAll($user),
        ]);
    }
}
