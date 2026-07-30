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
        $this->active = app(Switcher::class)->current();
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
