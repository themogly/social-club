<?php

namespace App\Livewire\Counter;

use App\Actions\Bar\CommitOrder;
use App\Actions\Bar\VoidOrder;
use App\Enums\TillSessionStatus;
use App\Exceptions\TillClosedException;
use App\Livewire\Counter\Concerns\IdentifiesOperator;
use App\Models\Article;
use App\Models\Location;
use App\Models\Member;
use App\Models\Order;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Money;
use App\Support\Settings;
use App\Support\Wallet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * The bar / merch POS — a tablet-first, full-page Livewire component on its own
 * authenticated route, OUTSIDE the Filament panel, sharing the `counter` layout with
 * the door, the till and the dispensary. A THIN shell over CommitOrder: it never
 * touches stock, money or the wallet directly. CommitOrder freezes the item snapshot,
 * depletes UNIT stock (SALE), optionally charges a member wallet (PURCHASE — member
 * required, enforced there) and posts cash to the SHARED till session; this screen only
 * resolves an optional socio, builds a basket of articles / miscellaneous lines and
 * calls it. Every figure — prices, stock, wallet balance — is queried LIVE on render
 * (mandate: transactional data is never cached).
 *
 * Articles only: a catalogue line must resolve to an Article at this sede, so a genetic
 * can never appear on a bar order. The bar shares the one drawer, so a commit is blocked
 * unless a till is OPEN.
 *
 * Fail-closed offline (mirrors the dispensary): because those figures are live-query by
 * mandate, a commit made offline cannot be trusted, so it is blocked (client + server)
 * and the basket is preserved until the connection returns.
 *
 * Vocabulary is deliberately SALE (venta / ticket / importe) — distinct from the cannabis
 * contribution vocabulary. A bar customer may be a socio or a guest.
 *
 * @phpstan-type BasketLine array{type: string, article_id?: string, description?: string, unit_price_cents?: int, qty: int, reference?: string}
 */
#[Layout('components.layouts.counter')]
class BarPos extends Component
{
    use IdentifiesOperator;

    // --- Identity / scope -------------------------------------------------------

    /** Live org-wide search to OPTIONALLY attach a socio (name or member number). */
    public string $search = '';

    /** The attached socio (id only — the model is resolved live). Optional: cash guests are fine. */
    public ?string $memberId = null;

    /** Live filter over the article grid (name). */
    public string $articleSearch = '';

    /** Category filter over the article grid (null = all). */
    public ?string $categoryId = null;

    /** The active location id, resolved in mount(). */
    public ?string $locationId = null;

    /** The till terminal this POS posts cash into (adopted from the single open session). */
    public string $terminal = '';

    /** Friendly state when the operator has no location at all (still a 200). */
    public bool $noLocation = false;

    /** True when the active sede has the bar turned off (per-location bar_enabled, prompt 59). */
    public bool $barDisabled = false;

    // --- Offline (fail-closed) --------------------------------------------------

    /** Set by the Alpine online/offline listener; the commit is refused while true. */
    public bool $offline = false;

    // --- Basket -----------------------------------------------------------------

    /**
     * The in-progress basket — minimal line data only; names, prices and totals are
     * re-resolved LIVE on every render so a mid-basket price change can never desync.
     *
     * @var list<BasketLine>
     */
    public array $basket = [];

    /** One idempotency key per basket — a double-tap or retry cannot double-commit. */
    public ?string $idempotencyKey = null;

    /** Optional order-level free-text reference (guests / rollout / event). */
    public string $reference = '';

    // --- Miscellaneous (quick amount) line entry --------------------------------

    public string $miscDescription = '';

    /** Euros at the edge; parsed to integer cents before it ever leaves the component. */
    public string $miscAmount = '';

    /** A miscellaneous line REQUIRES a reference (CommitOrder enforces it; so do we). */
    public string $miscReference = '';

    // --- Tender -----------------------------------------------------------------

    /** Wallet portion in euros (only meaningful with a socio attached). */
    public string $walletInput = '';

    /** Physical cash handed over, in euros — for the change-due display only. */
    public string $cashTendered = '';

    // --- Last order (receipt + void) --------------------------------------------

    public ?string $lastOrderId = null;

    public string $voidReason = '';

    // --- Flash ------------------------------------------------------------------

    public ?string $flashMessage = null;

    /** success | warning | error */
    public string $flashType = 'success';

    public function mount(): void
    {
        abort_unless($this->userCan('pos.bar'), 403);

        $scope = app(ActiveScope::class);
        $this->locationId = $scope->locationId();

        // No active location yet: fall back to the operator's first assigned sede.
        if ($this->locationId === null) {
            $first = $this->currentUser()?->locations()->where('active', true)->orderBy('name')->first();

            if ($first !== null) {
                $scope->setLocation($first->id);
                $this->locationId = $first->id;
            }
        }

        $this->noLocation = $this->locationId === null;

        // The bar can be turned off per sede (prompt 59) — refuse the POS with a friendly state.
        $this->barDisabled = $this->locationId !== null && ! (bool) Settings::get('bar_enabled', true);

        // Adopt the single open till at this sede so cash lands on the shared drawer.
        if ($this->terminal === '' && $this->locationId !== null) {
            $terminals = TillSession::query()->withoutGlobalScopes()
                ->where('location_id', $this->locationId)
                ->where('status', TillSessionStatus::OPEN->value)
                ->pluck('terminal');

            if ($terminals->count() === 1) {
                $this->terminal = (string) $terminals->first();
            }
        }

        $this->idempotencyKey = (string) Str::ulid();
    }

    // --- Attach / detach a socio (optional) -------------------------------------

    public function selectMember(string $memberId): void
    {
        $member = Member::query()->find($memberId);

        if ($member === null) {
            $this->flash(__('Socio no encontrado.'), 'error');

            return;
        }

        // The basket is kept — a socio can be attached at any point to enable wallet payment.
        $this->memberId = $member->id;
        $this->search = '';
    }

    public function clearMember(): void
    {
        $this->memberId = null;
        $this->search = '';
        $this->walletInput = '';
    }

    // --- Article grid → basket --------------------------------------------------

    public function filterCategory(?string $categoryId): void
    {
        $this->categoryId = $categoryId;
    }

    public function addArticle(string $articleId): void
    {
        $location = $this->resolveLocation();

        if ($location === null) {
            return;
        }

        // MUST resolve to an active Article at this sede. A genetic id (or anything else)
        // never resolves — that is how "no genetic on a bar order" is enforced at the edge.
        $article = Article::query()->where('location_id', $location->id)->active()->find($articleId);

        if ($article === null) {
            $this->flash(__('Artículo no disponible.'), 'error');

            return;
        }

        $index = $this->findArticleLine($article->id);
        $currentQty = $index !== null ? (int) $this->basket[$index]['qty'] : 0;

        if ($currentQty + 1 > (int) $article->stock) {
            $this->flash(__('No hay stock suficiente de :name.', ['name' => $article->name]), 'warning');

            return;
        }

        if ($index !== null) {
            $this->basket[$index]['qty'] = $currentQty + 1;
        } else {
            $this->basket[] = ['type' => 'article', 'article_id' => $article->id, 'qty' => 1];
        }

        $this->ensureIdempotencyKey();
        $this->flashMessage = null;
    }

    public function addMiscLine(): void
    {
        $description = trim($this->miscDescription);
        $reference = trim($this->miscReference);
        $cents = $this->parseCents($this->miscAmount);

        if ($description === '') {
            $this->flash(__('Indica una descripción para la línea manual.'), 'error');

            return;
        }

        if ($cents === null || $cents <= 0) {
            $this->flash(__('Introduce un importe válido.'), 'error');

            return;
        }

        // A miscellaneous line is NOT in the catalogue, so a reference is required.
        if ($reference === '') {
            $this->flash(__('Una línea manual requiere una referencia.'), 'error');

            return;
        }

        $this->basket[] = [
            'type' => 'misc',
            'description' => $description,
            'unit_price_cents' => $cents,
            'qty' => 1,
            'reference' => $reference,
        ];

        $this->reset(['miscDescription', 'miscAmount', 'miscReference']);
        $this->ensureIdempotencyKey();
        $this->flashMessage = null;
    }

    public function incrementLine(int $index): void
    {
        if (! isset($this->basket[$index])) {
            return;
        }

        $line = $this->basket[$index];

        if ($line['type'] === 'article') {
            $location = $this->resolveLocation();
            $article = $location !== null
                ? Article::query()->where('location_id', $location->id)->find((string) ($line['article_id'] ?? ''))
                : null;

            if ($article !== null && (int) $line['qty'] + 1 > (int) $article->stock) {
                $this->flash(__('No hay stock suficiente de :name.', ['name' => $article->name]), 'warning');

                return;
            }
        }

        $this->basket[$index]['qty'] = (int) $this->basket[$index]['qty'] + 1;
    }

    public function decrementLine(int $index): void
    {
        if (! isset($this->basket[$index])) {
            return;
        }

        $qty = (int) $this->basket[$index]['qty'];

        if ($qty <= 1) {
            $this->removeLine($index);

            return;
        }

        $this->basket[$index]['qty'] = $qty - 1;
    }

    public function removeLine(int $index): void
    {
        unset($this->basket[$index]);
        $this->basket = array_values($this->basket);
    }

    public function clearBasket(): void
    {
        $this->reset([
            'basket', 'walletInput', 'cashTendered', 'reference',
            'miscDescription', 'miscAmount', 'miscReference',
        ]);

        $this->idempotencyKey = (string) Str::ulid();
    }

    // --- Quick cash -------------------------------------------------------------

    /** Set the cash tendered to a preset (in cents) or, when null, the exact amount owed. */
    public function quickCash(?int $cents = null): void
    {
        $location = $this->resolveLocation();
        $total = $this->basketTotalCents($location);
        [$cashPosted] = $this->tenderSplit($total);

        $this->cashTendered = $this->eurosString($cents ?? $cashPosted);
    }

    // --- Commit -----------------------------------------------------------------

    public function commit(): void
    {
        $location = $this->resolveLocation();

        if ($location === null) {
            $this->flash(__('Sin sede activa.'), 'error');

            return;
        }

        // Attribution: a PIN-identified operator is required — never the device session user.
        if (! $this->requireOperator()) {
            return;
        }

        // Fail closed: a commit made offline cannot be trusted (stock/balances are live-query).
        if ($this->offline) {
            $this->flash(__('Sin conexión: no se puede cobrar. La cesta se conserva hasta reconectar.'), 'error');

            return;
        }

        if ($this->basket === []) {
            $this->flash(__('La cesta está vacía.'), 'error');

            return;
        }

        // A miscellaneous line requires a reference — CommitOrder enforces it; we validate
        // here too for a clear, immediate message.
        foreach ($this->basket as $line) {
            if ($line['type'] === 'misc' && trim((string) ($line['reference'] ?? '')) === '') {
                $this->flash(__('Una línea manual requiere una referencia.'), 'error');

                return;
            }
        }

        // The bar shares the one drawer — no open caja, no commit.
        $till = $this->openTillSession($location);

        if ($till === null) {
            $this->flash(__('No hay caja abierta en este terminal.'), 'error');

            return;
        }

        $total = $this->basketTotalCents($location);
        [$cashCents, $walletCents] = $this->tenderSplit($total);

        // Wallet requires a socio (CommitOrder enforces it; refuse early with a clear message).
        if ($walletCents > 0 && $this->memberId === null) {
            $this->flash(__('El pago con monedero requiere un socio.'), 'error');

            return;
        }

        if ($cashCents < 0 || $walletCents < 0 || ($cashCents + $walletCents) !== $total) {
            $this->flash(__('El desglose de pago (efectivo + monedero) no cuadra con el total.'), 'error');

            return;
        }

        $reference = trim($this->reference);

        $options = [
            'operator_id' => CounterOperator::id() ?? $this->currentUser()?->id,
            'till_session_id' => $till->id,
            'member_id' => $this->memberId,
            'cash_cents' => $cashCents,
            'wallet_cents' => $walletCents,
            'idempotency_key' => $this->idempotencyKey,
        ];

        if ($reference !== '') {
            $options['reference'] = $reference;
        }

        try {
            $order = (new CommitOrder)->handle($location, $this->orderLines(), $options);
        } catch (TillClosedException) {
            $this->flash(__('La caja no está abierta.'), 'error');

            return;
        } catch (ModelNotFoundException) {
            $this->flash(__('Algún artículo ya no está disponible.'), 'error');

            return;
        } catch (RuntimeException) {
            $this->flash(__('No se pudo registrar el pedido. Revisa la cesta y el stock.'), 'error');

            return;
        } catch (Throwable) {
            $this->flash(__('No se pudo registrar el pedido. Revisa la cesta y el stock.'), 'error');

            return;
        }

        $this->lastOrderId = $order->id;
        $this->resetBasketState();
        $this->flash(__('Pedido registrado.'), 'success');
    }

    // --- Void -------------------------------------------------------------------

    public function voidLast(): void
    {
        if ($this->lastOrderId === null) {
            return;
        }

        $user = $this->currentUser();

        if ($user === null || ! $user->can('order.void')) {
            $this->flash(__('No tienes permiso para anular un pedido.'), 'error');

            return;
        }

        $reason = trim($this->voidReason);

        if ($reason === '') {
            $this->flash(__('Indica el motivo de la anulación (queda registrado).'), 'error');

            return;
        }

        $order = Order::query()->withoutGlobalScopes()->find($this->lastOrderId);

        if ($order === null) {
            $this->flash(__('Pedido no encontrado.'), 'error');

            return;
        }

        try {
            (new VoidOrder)->handle($order, $user, $reason);
        } catch (AuthorizationException) {
            $this->flash(__('No tienes permiso para anular un pedido.'), 'error');

            return;
        } catch (RuntimeException) {
            $this->flash(__('No se pudo anular el pedido.'), 'error');

            return;
        }

        $this->voidReason = '';
        $this->lastOrderId = null;
        $this->flash(__('Pedido anulado. Stock y monedero revertidos.'), 'success');
    }

    // --- View data (assembled here; the view stays declarative) -----------------

    public function render(): View
    {
        $location = $this->resolveLocation();
        $member = $this->resolveMember();

        $walletCents = ($member !== null && $location !== null)
            ? Wallet::balance($member->id, $location->id)
            : 0;

        $allArticles = $this->articleRows($location);
        $basketLines = $this->basketView($location);
        $total = (int) array_sum(array_map(fn (array $l): int => (int) $l['line_total_cents'], $basketLines));
        [$cashPosted, $walletApplied] = $this->tenderSplit($total);

        return view('livewire.counter.bar-pos', [
            'location' => $location,
            'member' => $member,
            'walletCents' => $walletCents,
            'projectedWalletCents' => $walletCents - $walletApplied,
            'searchResults' => $this->searchResults(),
            'articles' => $this->filterArticles($allArticles),
            'categories' => $this->deriveCategories($allArticles),
            'basketLines' => $basketLines,
            'basketTotalCents' => $total,
            'cashPostedCents' => $cashPosted,
            'walletAppliedCents' => $walletApplied,
            'changeDueCents' => $this->changeDueCents($cashPosted),
            'openTill' => $location !== null ? $this->openTillSession($location) : null,
            'canVoid' => $this->userCan('order.void'),
        ]);
    }

    /** Money for display (integer cents), via the shared value object. */
    public function money(int $cents): string
    {
        return Money::fromCents($cents)->formatted();
    }

    // --- Tender resolution ------------------------------------------------------

    /**
     * Split the total into [cash, wallet] so cash + wallet == total (the Order model
     * asserts this invariant). The wallet portion reflects what was entered, clamped to
     * the total; the cash portion is the remainder. Whether a socio is present is checked
     * at commit — here we only arithmetic.
     *
     * @return array{0: int, 1: int}
     */
    private function tenderSplit(int $total): array
    {
        $wallet = min($this->requestedWalletCents(), max(0, $total));
        $cash = $total - $wallet;

        return [$cash, $wallet];
    }

    private function requestedWalletCents(): int
    {
        return max(0, $this->parseCents($this->walletInput) ?? 0);
    }

    private function changeDueCents(int $cashPosted): int
    {
        if (trim($this->cashTendered) === '') {
            return 0;
        }

        $tendered = $this->parseCents($this->cashTendered);

        if ($tendered === null || $tendered <= $cashPosted) {
            return 0;
        }

        return $tendered - $cashPosted;
    }

    private function parseCents(string $euros): ?int
    {
        if (trim($euros) === '') {
            return null;
        }

        try {
            return Money::fromEuros($euros)->cents;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** Integer cents → a plain euros string ("12.50") for an input default — float-free. */
    private function eurosString(int $cents): string
    {
        $cents = max(0, $cents);

        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    // --- Live view assembly (nothing cached) ------------------------------------

    /**
     * The basket, re-priced LIVE (catalogue lines from the Article's current price, misc
     * lines from their frozen amount) so a mid-basket price change can never desync the
     * shown total from the committed total.
     *
     * @return list<array<string, mixed>>
     */
    private function basketView(?Location $location): array
    {
        if ($location === null) {
            return [];
        }

        $rows = [];

        foreach ($this->basket as $index => $line) {
            $qty = max(1, (int) $line['qty']);

            if ($line['type'] === 'article') {
                $article = Article::query()->where('location_id', $location->id)->find((string) ($line['article_id'] ?? ''));

                if ($article === null) {
                    continue;
                }

                $unit = $article->price_cents->cents;

                $rows[] = [
                    'index' => $index,
                    'type' => 'article',
                    'name' => $article->name,
                    'unit_cents' => $unit,
                    'qty' => $qty,
                    'line_total_cents' => $unit * $qty,
                    'reference' => null,
                    'stock' => (int) $article->stock,
                ];

                continue;
            }

            $unit = (int) ($line['unit_price_cents'] ?? 0);

            $rows[] = [
                'index' => $index,
                'type' => 'misc',
                'name' => (string) ($line['description'] ?? ''),
                'unit_cents' => $unit,
                'qty' => $qty,
                'line_total_cents' => $unit * $qty,
                'reference' => (string) ($line['reference'] ?? ''),
                'stock' => null,
            ];
        }

        return $rows;
    }

    private function basketTotalCents(?Location $location): int
    {
        return (int) array_sum(array_map(
            fn (array $l): int => (int) $l['line_total_cents'],
            $this->basketView($location),
        ));
    }

    /**
     * The articles sellable at this sede — active, this location. Each row carries its
     * live unit price, remaining unit stock and a low-stock hint. ONLY Article records
     * are ever queried here; the genetics catalogue is never touched.
     *
     * @return list<array<string, mixed>>
     */
    private function articleRows(?Location $location): array
    {
        if ($location === null) {
            return [];
        }

        $articles = Article::query()
            ->where('location_id', $location->id)
            ->active()
            ->with('category')
            ->orderBy('name')
            ->get();

        $rows = [];

        foreach ($articles as $article) {
            $threshold = $article->low_stock_threshold;

            $rows[] = [
                'id' => $article->id,
                'name' => $article->name,
                'price_cents' => $article->price_cents->cents,
                'stock' => (int) $article->stock,
                'low_stock' => $threshold !== null && (int) $article->stock <= $threshold,
                'category_id' => $article->category_id,
                'category_name' => $article->category?->name,
                'image_url' => $this->imageUrl($article),
            ];
        }

        return $rows;
    }

    /**
     * Apply the grid's live name search + category filter (in memory — the sellable set
     * was already queried live above).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function filterArticles(array $rows): array
    {
        $term = mb_strtolower(trim($this->articleSearch));

        return array_values(array_filter($rows, function (array $row) use ($term): bool {
            if ($this->categoryId !== null && (string) ($row['category_id'] ?? '') !== $this->categoryId) {
                return false;
            }

            return $term === '' || str_contains(mb_strtolower((string) $row['name']), $term);
        }));
    }

    /**
     * Distinct categories among the sellable articles, for the grid filter chips.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{id: string, name: string}>
     */
    private function deriveCategories(array $rows): array
    {
        $seen = [];

        foreach ($rows as $row) {
            $id = $row['category_id'] ?? null;

            if ($id !== null && ! isset($seen[(string) $id])) {
                $seen[(string) $id] = (string) ($row['category_name'] ?? '—');
            }
        }

        $categories = [];

        foreach ($seen as $id => $name) {
            $categories[] = ['id' => (string) $id, 'name' => $name];
        }

        return $categories;
    }

    /** A public-disk URL to the article's first image, or null (→ intentional fallback). */
    private function imageUrl(Article $article): ?string
    {
        $first = data_get($article->images, '0');

        if (! is_string($first) || $first === '') {
            return null;
        }

        try {
            return Storage::disk('public')->url($first);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Org-wide socio search (crosses locations by design — Member is org-scoped). Null
     * until at least two characters are typed.
     *
     * @return Collection<int, Member>|null
     */
    private function searchResults(): ?Collection
    {
        $term = trim($this->search);

        if (mb_strlen($term) < 2) {
            return null;
        }

        return Member::query()
            ->where(fn ($q) => $q
                ->where('first_name', 'like', '%'.$term.'%')
                ->orWhere('last_name', 'like', '%'.$term.'%')
                ->orWhere('member_no', 'like', '%'.$term.'%'))
            ->orderBy('last_name')
            ->limit(8)
            ->get();
    }

    // --- Resolvers (live queries; nothing cached) -------------------------------

    private function resolveLocation(): ?Location
    {
        return $this->locationId !== null ? Location::query()->find($this->locationId) : null;
    }

    private function resolveMember(): ?Member
    {
        return $this->memberId !== null ? Member::query()->find($this->memberId) : null;
    }

    private function openTillSession(Location $location): ?TillSession
    {
        $query = TillSession::query()->withoutGlobalScopes()
            ->where('location_id', $location->id)
            ->where('status', TillSessionStatus::OPEN->value);

        if ($this->terminal !== '') {
            $query->where('terminal', $this->terminal);
        }

        return $query->orderBy('opened_at')->first();
    }

    // --- Small helpers ----------------------------------------------------------

    /**
     * Map the basket to CommitOrder's line shapes (catalogue vs miscellaneous).
     *
     * @return list<array{article_id?: string, description?: string, unit_price_cents?: int, qty: int, reference?: string}>
     */
    private function orderLines(): array
    {
        $lines = [];

        foreach ($this->basket as $line) {
            if ($line['type'] === 'article') {
                $lines[] = ['article_id' => (string) ($line['article_id'] ?? ''), 'qty' => (int) $line['qty']];

                continue;
            }

            $lines[] = [
                'description' => (string) ($line['description'] ?? ''),
                'unit_price_cents' => (int) ($line['unit_price_cents'] ?? 0),
                'qty' => (int) $line['qty'],
                'reference' => (string) ($line['reference'] ?? ''),
            ];
        }

        return $lines;
    }

    private function findArticleLine(string $articleId): ?int
    {
        foreach ($this->basket as $i => $line) {
            if ($line['type'] === 'article' && (string) ($line['article_id'] ?? '') === $articleId) {
                return $i;
            }
        }

        return null;
    }

    private function ensureIdempotencyKey(): void
    {
        if ($this->idempotencyKey === null) {
            $this->idempotencyKey = (string) Str::ulid();
        }
    }

    /** Clear the basket after a successful commit and mint the next idempotency key. */
    private function resetBasketState(): void
    {
        $this->reset([
            'basket', 'walletInput', 'cashTendered', 'reference',
            'miscDescription', 'miscAmount', 'miscReference', 'memberId', 'search',
        ]);

        $this->idempotencyKey = (string) Str::ulid();
    }

    private function userCan(string $permission): bool
    {
        return $this->currentUser()?->can($permission) ?? false;
    }

    private function currentUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    private function flash(string $message, string $type): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
    }
}
