<?php

namespace App\Livewire\Counter;

use App\Actions\Bar\VoidOrder;
use App\Actions\Dispensing\VoidDispensation;
use App\Enums\DashboardAlert;
use App\Enums\DispensationStatus;
use App\Enums\OrderStatus;
use App\Livewire\Counter\Concerns\IdentifiesOperator;
use App\Livewire\Counter\Concerns\ResolvesCounterLocation;
use App\Models\Dispensation;
use App\Models\Location;
use App\Models\MemberApplication;
use App\Models\Order;
use App\Models\User;
use App\Support\CounterLastSale;
use App\Support\CounterScreens;
use App\Support\CounterTerminals;
use App\Support\Money;
use App\Support\Period;
use App\Support\Settings;
use App\Support\Weight;
use App\ViewModels\Dashboard;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

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

    /** Prompt 347 — the reason typed in the hub's void sheet (300's last-sale line). */
    public string $voidReason = '';

    public function mount(): void
    {
        // Reachable by anyone who can reach ANY counter screen — it is the front door, not a destination of
        // its own. Someone with no counter permission at all has nothing to choose from and is refused.
        abort_unless(CounterScreens::reachableByAny($this->deviceUser()), 403);
        $this->resolveCounterLocation();

        // Prompt 337 — sent here from a switched-off Recepción: say why.
        if (is_string($notice = session('counterNotice'))) {
            $this->flash($notice, 'warning');
        }
    }

    /** Prompt 337 — does this sede record entries at the door? Off: no presence card, no «Entradas», and the tile below. */
    public function receptionEnabled(): bool
    {
        return CounterScreens::receptionEnabled($this->locationId);
    }

    /**
     * The tiles: exactly the screens this operator may open, from the shared list.
     *
     * @return list<array{route: string, label: string, granted: bool, icon: string}>
     */
    public function tiles(): array
    {
        return CounterScreens::reachableFor($this->deviceUser());
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
    /**
     * The one big tile — **its own decision since prompt 208**, not an accident of list order.
     *
     * It used to be `tiles()[0]`, and `CounterScreens::forUser()` said so in its own comment: *"Recepción is
     * first, and that is now load-bearing."* Nobody had chosen Recepción as the most important thing on the
     * counter — it is first in an array ordered for the tile grid's reading order and for
     * `landingRouteFor()`'s fallback, and 205 quietly made that ordering carry a design decision it was never
     * written to hold. The owner: *"I think dispensary should be the main button."*
     *
     * So it is a Setting, `counter_hero`, defaulting to the dispensary — the same shape 189 used for
     * `counter_landing`, and read through the accessor with a code default so a stale or missing value
     * degrades rather than throws.
     *
     * **Resolved per user, and that property is the reason this is not a one-liner.** A configured hero the
     * operator cannot open would be a tile to a 403; a till-only operator falls back to the first destination
     * they CAN open, which is exactly what this method did before, so nobody ever gets a hole where their
     * hero should be.
     *
     * @return array{route: string, label: string, granted: bool, icon: string}|null
     */
    public function heroTile(): ?array
    {
        $tiles = $this->tiles();
        $configured = (string) Settings::get('counter_hero', 'counter.pos');

        foreach ($tiles as $tile) {
            if ($tile['route'] === $configured) {
                return $tile;
            }
        }

        return $tiles[0] ?? null;
    }

    /**
     * @return list<array{route: string, label: string, granted: bool, icon: string}>
     */
    /**
     * Every other reachable destination, in `CounterScreens` order (337: led by *Nuevo socio* where Recepción is off).
     *
     * Filtered by ROUTE rather than by position since prompt 208: the hero is no longer necessarily the first
     * entry, so slicing the head off would have promoted the dispensary and then left Recepción out of the
     * grid entirely. Every reachable destination still renders exactly once — the hero is one of them
     * promoted, never a sixth tile.
     *
     * @return list<array{route: string, label: string, granted: bool, icon: string, key?: string, href?: string, purpose?: string, badge?: int}>
     */
    public function secondaryTiles(): array
    {
        $hero = $this->heroTile();

        $tiles = array_values(array_filter(
            $this->tiles(),
            fn (array $tile): bool => $tile['route'] !== ($hero['route'] ?? null),
        ));

        // Prompt 337 — where Recepción is off, *Nuevo socio* takes its place (first): Socios with the sign-up open
        // (`?alta=nuevo`), its pending list and 329's review, and 330's count when applications wait. Only for an
        // operator who may review — the gate of Socios' own *Nuevo socio*; for anyone else the space closes up.
        if (! $this->receptionEnabled() && $this->userCan('applications.review')) {
            array_unshift($tiles, [
                'key' => 'new-member',
                'route' => 'counter.members',
                'href' => route('counter.members', ['alta' => 'nuevo']),
                'label' => __('Nuevo socio'),
                'purpose' => __('Alta y solicitudes pendientes'),
                'granted' => true,
                'icon' => 'M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z',
                'badge' => $this->locationId !== null
                    ? MemberApplication::query()->withoutGlobalScopes()->where('location_id', $this->locationId)->awaitingReview()->count()
                    : 0,
            ]);
        }

        return $tiles;
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

    /** The rail's sentence per alert key, owned by the enum so the two dashboards cannot drift into different vocabularies. */
    public function alertLabel(string $key, int $count): string
    {
        return DashboardAlert::tryFrom($key)?->label($count) ?? $key;
    }

    /**
     * Where an attention item leads. An alert you cannot act on is decoration.
     *
     * Counter destinations where the counter can actually do something; the panel for the rest, and only
     * when this operator can reach the panel — otherwise the item still reports, without a dead link.
     */
    /**
     * Where an alert leads — the working screen, **with its worklist already open** (prompt 207).
     *
     * It led to a *screen* before: *"1 membresía vence pronto"* landed the operator on Socios, which is an
     * empty search box, and no way to find out WHICH membership without already knowing the answer. The alert
     * said something was wrong and then handed over a haystack.
     *
     * Naming the socio in the rail was the obvious fix and is the wrong one — 177 put the consumption list
     * behind a deliberate tap and bound it to one member precisely because this screen is on display in a
     * room with the next socio standing behind the current one. So the count stays here and the **names
     * appear at the far end**, on the screen where member data already belongs and where the operator is
     * about to act. The `alert` parameter is the filter; the destination resolves its own rows.
     *
     * Three of the seven have no counter destination at all ({@see DashboardAlert::counterRoute()}) — for a
     * user who can open the panel they go to the matching resource, and for everybody else they return null
     * and the rail renders them as plainly non-actionable text. An alert that lands a STAFF user on a 403 is
     * worse than one that does not link.
     */
    public function alertHref(string $key): ?string
    {
        $alert = DashboardAlert::tryFrom($key);

        if ($alert === null) {
            return null;
        }

        $route = $alert->counterRoute();

        // A counter destination only counts if this operator may actually open it — the tile list IS the
        // permission list, so an alert can never be a way around a gate the hub itself respects.
        if ($route !== null && collect($this->tiles())->contains('route', $route)) {
            return route($route, ['alert' => $alert->value]);
        }

        // Panel access is not the same as access to the TABLE the alert points at — ask the resource's own
        // policy. Without this a STAFF operator, who holds panel access and no `viewAny` on Batches, was
        // handed a 403 by an alert.
        return ($this->canReachPanel() && $alert->panelDestinationIsOpenToActor())
            ? $alert->panelUrl()
            : null;
    }

    /** Memoised for the request: every panel reads the same instance, so the queries are counted once. */
    private ?Dashboard $dashboard = null;

    private function dashboard(): Dashboard
    {
        $user = $this->counterActor();

        // The OPERATOR's figures (prompt 255) — a staff PIN on an owner-logged tablet must not see the owner's
        // takings. The view renders the panels only once someone is identified, so this cannot be null in
        // practice; it is asserted rather than assumed, because a null here would silently widen the gate.
        abort_if($user === null, 403);

        // The counter sede's BUSINESS day (prompt 271) — it used to be the UTC calendar day, so the takings reset at 02:00.
        return $this->dashboard ??= Dashboard::for($user, Period::today($this->resolveLocation()));
    }

    /**
     * The sedes this operator may work at — the same validated list the switcher route enforces against, so
     * the home screen can never offer a sede the POST would refuse.
     *
     * @return Collection<int, Location>
     */
    public function availableSedes(): Collection
    {
        return CounterTerminals::availableSedes($this->deviceUser()); // the terminal's home sede before a PIN (289)
    }

    /** Can this user reach the admin panel? The same gate the sidebar and the old overflow menu used. */
    public function canReachPanel(): bool
    {
        $user = $this->deviceUser();

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

    /**
     * Prompt 347 — the sale just recorded, when the sede comes back here after recording ({@see CounterLastSale}).
     *
     * @return array{summary: string, receiptUrl: ?string, receiptLabel: string, receiptHeading: string, canVoid: bool, voidHeading: string}|null
     */
    public function hubLastSale(): ?array
    {
        $sale = CounterLastSale::current($this->locationId);
        if ($sale === null) {
            return null;
        }
        [$dispensation, $order] = $this->hubSaleRecords($sale);
        if ($dispensation === null && $order === null) {
            return null;
        }
        $location = $this->resolveLocation();
        $actor = $this->hasOperator() ? $this->counterActor() : null;

        if ($dispensation !== null) {
            return [
                'summary' => __('Última: :total · :grams · :time', [
                    'total' => $dispensation->total_cents->formatted(),
                    'grams' => Weight::fromCentigrams($dispensation->dispensedGramsCg())->formatted(),
                    'time' => local_datetime($dispensation->created_at, 'H:i', $location),
                ]),
                'receiptUrl' => route('counter.pos.receipt', $dispensation->id),
                'receiptLabel' => __('Ver / imprimir recibo'),
                'receiptHeading' => __('Recibo'),
                'canVoid' => $actor?->can('dispensation.void') ?? false,
                'voidHeading' => __('Anular la dispensación'),
            ];
        }

        return [
            'summary' => __('Última venta: :total · :time', [
                'total' => $order->total_cents->formatted(),
                'time' => local_datetime($order->created_at, 'H:i', $location),
            ]),
            'receiptUrl' => (bool) Settings::get('bar_receipt_enabled', false, $this->locationId) ? route('counter.bar.receipt', $order->id) : null,
            'receiptLabel' => __('Ver / imprimir ticket'),
            'receiptHeading' => __('Ticket'),
            'canVoid' => $actor?->can('order.void') ?? false,
            'voidHeading' => __('Anular la venta'),
        ];
    }

    /**
     * The hub's void: the dispensation when the sale had one (a combined visit's bar part is voided from the panel, as
     * from the dispensary's own line), else the bar order. The same writers and checks as the screens'.
     */
    public function voidLast(): void
    {
        $sale = CounterLastSale::current($this->locationId);
        if ($sale === null || ! $this->requireOperator()) {
            return;
        }
        [$dispensation, $order] = $this->hubSaleRecords($sale);
        $user = $this->counterActor();
        $reason = trim($this->voidReason);
        if ($reason === '') {
            $this->flash(__('Indica el motivo de la anulación (queda registrado).'), 'error');

            return;
        }

        try {
            if ($dispensation !== null) {
                if ($user === null || ! $user->can('dispensation.void')) {
                    throw new AuthorizationException;
                }
                (new VoidDispensation)->handle($dispensation, $user, $reason);
                $this->flash(__('Dispensación anulada. Stock y monedero revertidos.'), 'success');
            } elseif ($order !== null) {
                if ($user === null || ! $user->can('order.void')) {
                    throw new AuthorizationException;
                }
                (new VoidOrder)->handle($order, $user, $reason);
                $this->flash(__('Pedido anulado. Stock y monedero revertidos.'), 'success');
            }
        } catch (AuthorizationException) {
            $this->flash(__('No tienes permiso para anular.'), 'error');

            return;
        } catch (RuntimeException) {
            $this->flash(__('No se pudo anular.'), 'error');

            return;
        }

        $this->voidReason = '';
        CounterLastSale::forget();
    }

    /**
     * The records, at THIS sede only, still completed (a voided one shows nothing).
     *
     * @param  array{dispensation_id: ?string, order_id: ?string}  $sale
     * @return array{0: ?Dispensation, 1: ?Order}
     */
    private function hubSaleRecords(array $sale): array
    {
        $dispensation = $sale['dispensation_id'] !== null
            ? Dispensation::query()->withoutGlobalScopes()->where('location_id', $this->locationId)->where('status', DispensationStatus::COMPLETED)->find($sale['dispensation_id'])
            : null;
        $order = $sale['order_id'] !== null
            ? Order::query()->withoutGlobalScopes()->where('location_id', $this->locationId)->where('status', OrderStatus::COMPLETED)->find($sale['order_id'])
            : null;

        return [$dispensation, $order];
    }

    protected function flash(string $message, string $type): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
    }
}
