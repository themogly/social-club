<?php

namespace App\Livewire\Counter;

use App\Livewire\Counter\Concerns\IdentifiesOperator;
use App\Livewire\Counter\Concerns\ResolvesCounterLocation;
use App\Models\Location;
use App\Models\User;
use App\Support\CounterScreens;
use App\Support\LocationSwitcher;
use App\Support\Money;
use App\Support\Period;
use App\ViewModels\Dashboard;
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
     * The hero tile — the FIRST destination this operator may open, in `CounterScreens` order.
     *
     * That order starts at Recepción, and Recepción earns the big tile on frequency, not on revenue: every
     * visit starts at the door, and `DESIGN-counter-first.md`'s research is that these products land on a
     * queue of people rather than a menu. The mockup made Dispensario the large one because dispensing is
     * the act that takes the money; the door is the act that happens most.
     *
     * Deriving it from the list rather than naming a route also makes it degrade by role for free: a
     * till-only operator's hero is Caja, and nobody ever gets a hole where their hero should be.
     *
     * @return array{route: string, label: string, granted: bool, icon: string}|null
     */
    public function heroTile(): ?array
    {
        return $this->tiles()[0] ?? null;
    }

    /**
     * @return list<array{route: string, label: string, granted: bool, icon: string}>
     */
    public function secondaryTiles(): array
    {
        return array_slice($this->tiles(), 1);
    }

    /**
     * Every live figure on this screen, from `App\ViewModels\Dashboard` and nowhere else.
     *
     * 177's rule, applied to a new screen: if a number here ever disagrees with the panel or the dispensary,
     * **this screen is wrong and the resolver is right.** So there is no second query for anything Dashboard
     * already computes, and the one figure it did not compute (`checkInsToday`) was added to Dashboard rather
     * than here.
     *
     * **Money is absent unless the operator may see money.** `canSeeFinance` is Dashboard's existing rule
     * (`reports.view` / `reports.view.all`), which STAFF hold neither of — see DECISIONS. The panel is then
     * absent, not empty and not zeroed.
     *
     * @return array{inside: int, check_ins: int, transactions: int, taken: ?string, alerts: list<array{severity: string, key: string, count: int}>, on_shift: list<string>}
     */
    public function panels(): array
    {
        $dashboard = $this->dashboard();

        return [
            'inside' => $dashboard->insideNow(),
            'check_ins' => $dashboard->checkInsToday(),
            'transactions' => $dashboard->transactionCount(),
            'taken' => $dashboard->canSeeFinance
                ? Money::fromCents($dashboard->contributionsCents())->formatted()
                : null,
            'alerts' => $dashboard->alerts(),
            'on_shift' => $dashboard->operatorsOnShift(),
        ];
    }

    /** May this operator see money at all? Drives the panel's presence, never a blurred or zeroed figure. */
    public function canSeeTakings(): bool
    {
        return $this->dashboard()->canSeeFinance;
    }

    /** A sentence per alert key — never a raw slug on a screen a person reads. */
    public function alertLabel(string $key, int $count): string
    {
        return match ($key) {
            'members_over_limit' => trans_choice(':count socio ha superado su límite|:count socios han superado su límite', $count, ['count' => $count]),
            'active_member_cap' => __('El club está en su tope de socios activos'),
            'unreconciled_till' => trans_choice(':count caja sin cerrar|:count cajas sin cerrar', $count, ['count' => $count]),
            'batches_expiring' => trans_choice(':count lote caduca pronto|:count lotes caducan pronto', $count, ['count' => $count]),
            'stock_ceiling_exceeded' => __('Stock por encima del techo legal en esta sede'),
            'memberships_expiring' => trans_choice(':count membresía vence pronto|:count membresías vencen pronto', $count, ['count' => $count]),
            'pending_applications' => trans_choice(':count solicitud pendiente|:count solicitudes pendientes', $count, ['count' => $count]),
            default => $key,
        };
    }

    /**
     * Where an attention item leads. An alert you cannot act on is decoration.
     *
     * Counter destinations where the counter can actually do something; the panel for the rest, and only
     * when this operator can reach the panel — otherwise the item still reports, without a dead link.
     */
    public function alertHref(string $key): ?string
    {
        $route = match ($key) {
            'unreconciled_till' => 'counter.till',
            'memberships_expiring', 'pending_applications' => 'counter.members',
            'members_over_limit' => 'counter.pos',
            default => null,
        };

        if ($route === null) {
            return $this->canReachPanel() ? url('/') : null;
        }

        return collect($this->tiles())->contains('route', $route) ? route($route) : null;
    }

    /** Memoised for the request: every panel reads the same instance, so the queries are counted once. */
    private ?Dashboard $dashboard = null;

    private function dashboard(): Dashboard
    {
        $user = $this->currentUser();

        // mount() has already 403'd anyone who is not a counter user, so this cannot be null in practice —
        // it is asserted rather than assumed, because a null here would silently widen the finance gate.
        abort_if($user === null, 403);

        return $this->dashboard ??= Dashboard::for($user, Period::today());
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
