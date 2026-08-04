<?php

namespace App\Livewire;

use App\Support\LocationSwitcher as Switcher;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Topbar location switcher. Lists the user's assigned locations (OWNER also "All
 * locations"); selecting one sets the active location (LocationScope) for the
 * session and reloads. Server-validated against the user's assignments.
 */
class LocationSwitcher extends Component
{
    public ?string $active = null;

    public function mount(): void
    {
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

    public function switchTo(?string $locationId): void
    {
        $user = Auth::user();
        $target = ($locationId === '' || $locationId === null) ? null : $locationId;

        if ($user !== null && app(Switcher::class)->switch($user, $target)) {
            $this->redirect('/', navigate: false);
        }
    }

    public function render(): View
    {
        $switcher = app(Switcher::class);
        $user = Auth::user();

        return view('livewire.location-switcher', [
            'locations' => $user !== null ? $switcher->available($user) : collect(),
            'canSwitchToAll' => $user !== null && $switcher->canSwitchToAll($user),
        ]);
    }
}
