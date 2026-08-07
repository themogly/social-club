<?php

namespace App\Livewire\Counter;

use App\Livewire\Counter\Concerns\IdentifiesOperator;
use App\Livewire\Counter\Concerns\ResolvesCounterLocation;
use App\Models\Location;
use App\Models\User;
use App\Support\CounterScreens;
use App\Support\LocationSwitcher;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The counter's front door (prompt 189) — one large tile per destination, sized for a finger on a tablet.
 *
 * Two reports, one cause. The owner asked twice for "a page with big grid icons for all the sections", and
 * separately that "the menu at the top is too cramped". They are the same problem: the bar was doing a home
 * screen's job. It filled up honestly — prompt 132 folded the secondary actions into one overflow so five
 * destinations would fit, then prompt 173 retired the operator strip and moved "Trabajando: …" into the same
 * row — and each step was right on its own. The row was full before the last one arrived.
 *
 * This is the one counter screen that can afford to be generous: it is a CHOOSER, not a working surface.
 * Every tile is 8rem tall against the counter's 44px floor, because that is the whole reason a hub beats a
 * menu bar on a tablet.
 *
 * ONE source for the destinations and their gates: {@see CounterScreens}, the same list the tab strip reads.
 * Prompt 172 extracted it precisely so there would not be two, and a tile to a screen the operator cannot
 * open is the same defect as a link to a 403.
 *
 * It is NOT a way around a precondition. It sits behind prompt 175's blocking chain like every other counter
 * screen: no sede still blocks, in the same order, and 173's surface still owns identifying.
 */
#[Layout('components.layouts.counter')]
class CounterHome extends Component
{
    use IdentifiesOperator, ResolvesCounterLocation;

    /** #[Locked] (prompt 75): the client can never retarget the sede. */
    #[Locked]
    public ?string $locationId = null;

    public bool $noLocation = false;

    public ?string $flashMessage = null;

    public string $flashType = 'success';

    public function mount(): void
    {
        // Reachable by anyone who can reach ANY counter screen — it is the front door, not a destination of
        // its own. Someone with no counter permission at all has nothing to choose from and is refused.
        abort_unless(CounterScreens::reachableByAny($this->currentUser()), 403);
        $this->resolveCounterLocation();
    }

    /**
     * The tiles: exactly the screens this operator may open, from the shared list.
     *
     * @return list<array{route: string, label: string, granted: bool, icon: string}>
     */
    public function tiles(): array
    {
        return CounterScreens::reachableFor($this->currentUser());
    }

    /**
     * The sedes this operator may work at — the same validated list the switcher route enforces against, so
     * the home screen can never offer a sede the POST would refuse.
     *
     * @return Collection<int, Location>
     */
    public function availableSedes(): Collection
    {
        $user = $this->currentUser();

        return $user !== null ? app(LocationSwitcher::class)->available($user) : collect();
    }

    /** Can this user reach the admin panel? The same gate the sidebar and the old overflow menu used. */
    public function canReachPanel(): bool
    {
        $user = $this->currentUser();

        return $user !== null && $user->canAccessPanel(Filament::getPanel('admin'));
    }

    public function render(): View
    {
        $this->applyCounterScope();

        return view('livewire.counter.counter-home', [
            'location' => $this->resolveLocation(),
        ]);
    }

    private function resolveLocation(): ?Location
    {
        return $this->locationId !== null ? Location::query()->find($this->locationId) : null;
    }

    private function currentUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    public function userCan(string $permission): bool
    {
        return $this->currentUser()?->can($permission) ?? false;
    }

    protected function flash(string $message, string $type): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
    }
}
