<?php

namespace App\Livewire\Counter;

use App\Actions\Attendance\ResolveMemberEligibility;
use App\Actions\Bar\CommitOrder;
use App\Actions\Counter\CommitCombinedSettle;
use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Dispensing\ResolveMemberLimits;
use App\Actions\Dispensing\VoidDispensation;
use App\Actions\Pricing\ResolveArticleDiscount;
use App\Actions\Pricing\ResolvePrice;
use App\Actions\ResolveLocale;
use App\Actions\Stock\AllocateFromBatches;
use App\Actions\Stock\SelectBatch;
use App\Actions\Till\SelectTillSession;
use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\DispensationStatus;
use App\Enums\ProductType;
use App\Enums\TillSessionStatus;
use App\Enums\WalletTransactionType;
use App\Exceptions\DebtLimitExceededException;
use App\Exceptions\DispensationBlockedException;
use App\Exceptions\LimitExceededException;
use App\Exceptions\StockUnavailableException;
use App\Exceptions\TillClosedException;
use App\Livewire\Counter\Concerns\AddsManualBarLines;
use App\Livewire\Counter\Concerns\CollectsMembershipFees;
use App\Livewire\Counter\Concerns\FindsMembers;
use App\Livewire\Counter\Concerns\HandlesTender;
use App\Livewire\Counter\Concerns\IdentifiesOperator;
use App\Livewire\Counter\Concerns\OpensMemberships;
use App\Livewire\Counter\Concerns\PersistsBasket;
use App\Livewire\Counter\Concerns\RendersIslandsOnChange;
use App\Livewire\Counter\Concerns\ResolvesCounterLocation;
use App\Livewire\Counter\Concerns\ShowsSettledOutcome;
use App\Mail\DispensationReceiptMail;
use App\Models\Article;
use App\Models\Batch;
use App\Models\CheckIn;
use App\Models\Dispensation;
use App\Models\DispensationLine;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberSanction;
use App\Models\Membership;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ArticleImage;
use App\Support\BusinessDay;
use App\Support\CounterOperator;
use App\Support\CounterScreens;
use App\Support\DocumentVault;
use App\Support\EligibilityVerdict;
use App\Support\LimitSnapshot;
use App\Support\ManagerApproval;
use App\Support\Money;
use App\Support\PriceResult;
use App\Support\Settings;
use App\Support\SettledOutcome;
use App\Support\StockCover;
use App\Support\TrainingMode;
use App\Support\VaultUrl;
use App\Support\Wallet;
use App\Support\Weight;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Component;
use RuntimeException;

/**
 * The dispensary POS — a tablet-first, full-page Livewire component on its own
 * authenticated route, OUTSIDE the Filament panel, sharing the `counter` layout with
 * the door and the till. A THIN shell over the domain Actions: it never touches
 * stock, money, limits or pricing directly. CommitDispensation is THE compliance
 * boundary (membership/carencia/limits/stock/pricing enforced atomically); this
 * screen resolves a socio, builds a basket, and calls it. Every figure — limits,
 * balances, stock, prices — is queried LIVE on render (mandate: transactional data
 * is never cached).
 *
 * Fail-closed offline: because those figures are live-query by mandate, a commit made
 * while offline cannot be trusted, so the commit is blocked (client + server) and the
 * basket is preserved until the connection returns.
 *
 * @phpstan-type Line array{genetic_id: string, batch_id: ?string, grams_cg: int, units: ?int}
 * @phpstan-type Rule array{rule: string, satisfied: bool, mode: string, message: string}
 */
#[Layout('components.layouts.counter', ['fullHeight' => true])] // prompt 176: the page must not scroll; the selection pane does
class DispensaryPos extends Component
{
    use AddsManualBarLines, CollectsMembershipFees, FindsMembers, HandlesTender, IdentifiesOperator, OpensMemberships, PersistsBasket, RendersIslandsOnChange, ResolvesCounterLocation, ShowsSettledOutcome;

    // --- Identity ---------------------------------------------------------------
    // The ONE lookup field ($lookup) and everything behind it live in FindsMembers (prompt 194). This screen
    // used to carry two stacked inputs of its own — a scan box above a name box, each already doing the
    // other's job — plus its own copy of the org-wide search query.

    /**
     * Genetics: LIST by default, grid available, remembered per screen (prompt 176).
     *
     * Loyverse is the only vendor publishing guidance on this and it says grid when items carry images and
     * you want density, list when names are long or the operator needs to see prices without an extra tap.
     * A genetic carries THC, CBD, category, strain and remaining stock — five figures that do not fit a
     * tile, which is exactly why Treez (the cannabis one) is search-first. So the default is LIST here and
     * GRID on the bar, where an article is a name and a price.
     *
     * #[Session] rather than a column: it is a per-operator display preference, not club data, and it must
     * survive a reload without a migration or a write on every toggle. Since prompt 293 the toggle is the
     * browser's (a view change makes no request); it hands the choice back with `$wire.$set(…, false)`, which
     * rides on the next real request instead of making one.
     */
    #[Session(key: 'counter.pos.genetic_layout')]
    public string $geneticLayout = 'list';

    /**
     * …and the bar's own layout, which the docblock above promised and the code never gave it (prompt 225).
     * One toggle, two remembered choices — it writes to whichever source is on screen.
     */
    #[Session(key: 'counter.pos.article_layout')]
    public string $articleLayout = 'grid';

    /** @var array<string, string> the layouts as they were before the browser's choice arrived */
    private array $layoutBefore = [];

    /** The toggle's choice arrives from the browser (prompt 293): anything but list/grid is ignored rather than stored (176). */
    public function updatingGeneticLayout(): void
    {
        $this->layoutBefore['geneticLayout'] = $this->geneticLayout;
    }

    public function updatingArticleLayout(): void
    {
        $this->layoutBefore['articleLayout'] = $this->articleLayout;
    }

    public function updatedGeneticLayout(string $value): void
    {
        if (! in_array($value, ['list', 'grid'], true)) {
            $this->geneticLayout = $this->layoutBefore['geneticLayout'] ?? 'list';
        }
    }

    public function updatedArticleLayout(string $value): void
    {
        if (! in_array($value, ['list', 'grid'], true)) {
            $this->articleLayout = $this->layoutBefore['articleLayout'] ?? 'grid';
        }
    }

    /** The held socio (id only — the model is resolved live, never stored on the component). */
    public ?string $memberId = null;

    /** True when the held member arrived via a scanned card. */
    public bool $scanned = false;

    /** The active location id, resolved in mount(). #[Locked] (prompt 75): the client can never retarget the counter's sede. */
    #[Locked]
    public ?string $locationId = null;

    /** The till terminal this POS contributes cash into (adopted from the single open session). */
    public string $terminal = '';

    /** Friendly state when the operator has no location at all (still a 200). */
    public bool $noLocation = false;

    // --- Offline (fail-closed) --------------------------------------------------

    /** Set by the Alpine online/offline listener; the commit is refused while true. */
    public bool $offline = false;

    // --- Basket -----------------------------------------------------------------

    /**
     * The in-progress basket — minimal line data only; names, prices and totals are
     * re-resolved LIVE on every render so a mid-basket price change can never desync.
     *
     * @var list<Line>
     */
    public array $basket = [];

    /** One idempotency key per basket — a double-tap or retry cannot double-commit. */
    public ?string $idempotencyKey = null;

    /**
     * The OPTIONAL bar/merch side of the same visit (prompt 118): a list of {article_id, qty} — and, since prompt 331,
     * manual bar lines {description, unit_price_cents, reference, qty} (no article_id). When present at settle, the
     * visit is committed through CommitCombinedSettle — one payment, but a Dispensation AND an Order on their separate
     * ledgers. Empty (the common case) leaves the plain dispensation commit untouched.
     *
     * @var list<array{article_id: string, qty: int}|array{description: string, unit_price_cents: int, reference: string, qty: int}>
     */
    public array $barBasket = [];

    /** The just-committed bar Order from a combined settle — the SECOND receipt offered alongside the first. */
    public ?string $lastOrderId = null;

    // --- Weight entry -----------------------------------------------------------

    /** The genetic currently being weighed (its panel is open). */
    public ?string $activeGeneticId = null;

    /** The chosen batch for the active genetic — defaults to FEFO, operator-overridable. */
    public ?string $activeBatchId = null;

    /** The numeric-pad value: grams (2 dp) normally, euros in calculator mode (WEIGHT genetics). */
    public string $weightInput = '';

    /** Calculator mode: the operator types euros; we back-solve grams (rounded DOWN to 0.01 g). */
    public bool $calculatorMode = false;

    /** The unit-stepper value for a UNIT genetic (preroll/edible). Grams are computed from it. */
    public int $unitQty = 1;

    // Tender state ($walletInput, $cashTendered) + the split/change/quick-cash logic live in HandlesTender.
    // Cash entered is what the member HANDED (for change), never the amount to charge (prompt 74).

    // --- Override (permissioned, reasoned) --------------------------------------

    /** A limit breach / OVERRIDE-mode block needs a reasoned, permissioned override to proceed. */
    public bool $requireOverride = false;

    /** The last attempt hit a consumption-limit breach (offer the override). */
    public bool $limitBreach = false;

    public string $overrideReason = '';

    // --- Price override (permissioned, reasoned — prompt 64) --------------------

    /** The new total the member pays for the whole contribution, in euros. Empty = charge the resolved price. */
    public string $priceOverrideEuros = '';

    public string $priceOverrideReason = '';

    // --- Signature --------------------------------------------------------------

    /** Path on the PRIVATE documents disk once a signature has been captured this basket. */
    public ?string $signaturePath = null;

    // --- Void -------------------------------------------------------------------

    /** The just-committed dispensation (offer the receipt + a void affordance). */
    public ?string $lastDispensationId = null;

    public string $voidReason = '';

    // --- Flash ------------------------------------------------------------------

    public ?string $flashMessage = null;

    /** success | warning | error */
    public string $flashType = 'success';

    /**
     * Bumped by every `flash()` and joined to the message's key (prompt 279, post-296 completeness D3). Without it the
     * same confirmation twice morphed onto the first one's already-faded element and showed nothing.
     */
    #[Locked]
    public int $flashSeq = 0;

    public function mount(): void
    {
        abort_unless($this->deviceCan('pos.use'), 403);

        // Resolve the counter's OWN working sede (session key counter.location_id) — never the panel
        // scope, never a silent guess. One assigned sede is adopted; several ⇒ ask (mustChooseLocation).
        $this->resolveCounterLocation();

        // Adopt the single open till at this sede so cash contributions land on it.
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

        // Prompt 205 — a basket left on this screen comes back. Last, because it needs the sede above.
        $this->restoreBasket();
    }

    protected function basketScreen(): string
    {
        return 'pos';
    }

    // --- Identify ---------------------------------------------------------------

    /**
     * Prompt 194 — the shared lookup identified somebody; the POS holds them for a basket.
     *
     * The sede's check-in requirement is enforced inside holdMember(), which is AFTER this point on purpose:
     * the difference between this screen and the door belongs after the member is found, never inside the
     * shared lookup. A socio who has not checked in is REFUSED with a message that says so, rather than
     * being quietly filtered out of the results — "no results" for a member standing at the counter is the
     * least useful thing the screen could say.
     */
    protected function onMemberFound(Member $member, bool $scanned): void
    {
        // This screen is member-FIRST: identifying a socio is the start of the next dispensación, so the
        // previous one's confirmation goes now. (The bar is basket-first — a socio can be attached mid-basket
        // to pay by wallet — so there the article add is what clears it.)
        $this->dismissOutcome();

        $this->holdMember($member->id, $scanned);
    }

    /**
     * Collect the held socio's outstanding fee inline on the POS card (prompt 127), through the SAME shared
     * concern the till and Socios tab use — so the operator does not have to break off a basket to send them to
     * another screen. A CASH fee needs the open till; a WALLET fee does not. On success the counter verdict
     * re-resolves and the unpaid_fee warning clears itself, unblocking the dispensation.
     */
    public function collectMemberFee(): void
    {
        if (! $this->requireOperator()) {
            return;
        }

        $user = $this->counterActor();
        if ($user === null || ! $user->can('membership.fee.collect')) {
            $this->flash(__('No tienes permiso para cobrar cuotas.'), 'error');

            return;
        }

        $location = $this->resolveLocation();
        $member = $this->resolveMember();
        if ($location === null || $member === null) {
            return;
        }

        $result = $this->collectInlineFeeFor($member, $this->openTillSession($location), $location, $user);
        $this->flashResult($result);
    }

    /** Set when dropping the member would discard unpaid lines — the view asks before anything is lost (prompt 263). */
    public bool $confirmDiscard = false;

    /**
     * Close the held member. With anything unpaid in either basket this ASKS first (prompt 263): "Cerrar" used to
     * discard both baskets outright, so the ordinary sequence "commit the aportación, tap Cerrar, next member"
     * handed the drinks over unpaid and unrecorded. `$confirmed` is the operator's answer to that question.
     */
    public function clearMember(bool $confirmed = false): void
    {
        if (! $confirmed && $this->hasUnpaidLines()) {
            $this->confirmDiscard = true;

            return;
        }

        $this->confirmDiscard = false;
        $this->reset([
            'memberId', 'scanned', 'lookup', 'lookupSearched', 'basket', 'activeGeneticId', 'activeBatchId',
            'weightInput', 'calculatorMode', 'unitQty', 'cashTendered', 'walletInput', 'requireOverride',
            'limitBreach', 'overrideReason', 'priceOverrideEuros', 'priceOverrideReason', 'signaturePath',
            'lastDispensationId', 'lastOrderId', 'barBasket', 'voidReason', 'flashMessage',
        ]);

        // A new socio always starts a fresh basket → a fresh idempotency key.
        $this->idempotencyKey = (string) Str::ulid();
    }

    /** Keep the member and the baskets — the answer "no" to {@see clearMember()}'s question. */
    public function keepUnpaid(): void
    {
        $this->confirmDiscard = false;
    }

    /** Is anything in either basket that has not been paid for? */
    private function hasUnpaidLines(): bool
    {
        return $this->basket !== [] || $this->barBasket !== [];
    }

    // --- Genetic → weight → basket ----------------------------------------------

    public function chooseGenetic(string $geneticId): void
    {
        if ($this->resolveMember() === null) {
            $this->flash(__('Identifica a un socio antes de añadir producto.'), 'error');

            return;
        }

        // The operator is on to the next socio's product — the previous confirmation goes (prompt 202).
        $this->dismissOutcome();

        $this->activeGeneticId = $geneticId;
        $this->weightInput = '';
        $this->calculatorMode = false;
        $this->unitQty = 1;

        // Manual mode: default the batch to FEFO (oldest open, non-expired, in stock), overridable below.
        // Automatic mode (prompt 250): no lote is chosen here — allocation happens at commit — so leave it null.
        $location = $this->resolveLocation();
        $genetic = Genetic::query()->find($geneticId);
        $this->activeBatchId = ($location !== null && $genetic !== null && ! $this->automaticBatches())
            ? (new SelectBatch)->fefo($genetic, $location)?->id
            : null;

        // Prompt 333 — the pad comes into view (client-side, window.bringIntoView), also for the same strain tapped again.
        $this->dispatch('weight-entry-opened');
    }

    /**
     * Prompt 250 — automatic (the system draws from the sede's batches oldest-first, splitting when the old
     * one runs out) vs manual (the operator picks the lote). Per-sede setting, default automatic.
     */
    public function automaticBatches(): bool
    {
        return Settings::get('dispensary_batch_selection', 'automatic', $this->resolveLocation()?->id) !== 'manual';
    }

    /** The sede's dispensable total of the active genetic, formatted — shown on the pane in automatic mode. */
    public function activeGeneticStockLabel(): ?string
    {
        $location = $this->resolveLocation();
        $genetic = $this->activeGeneticId !== null ? Genetic::query()->find($this->activeGeneticId) : null;

        if ($location === null || $genetic === null) {
            return null;
        }

        if ($genetic->isUnitType()) {
            $units = $this->remainingUnits($genetic, $location);

            return trans_choice(':count unidad|:count unidades', $units, ['count' => $units]);
        }

        return $this->grams($this->remainingCg($genetic, $location));
    }

    public function cancelWeightEntry(): void
    {
        $this->reset(['activeGeneticId', 'activeBatchId', 'weightInput', 'calculatorMode', 'unitQty']);
    }

    public function selectBatch(string $batchId): void
    {
        $this->activeBatchId = $batchId;
    }

    /** Unit stepper for a UNIT genetic — never below one unit. */
    public function stepUnits(int $delta): void
    {
        $this->unitQty = max(1, $this->unitQty + $delta);
    }

    /**
     * Whether the bar is offered on this screen: the sede runs a bar (prompt 118's setting) AND the PIN operator may
     * sell at it (`pos.bar`, prompt 266 — the operator decides, 255). Without the permission there is no bar source at
     * all, and the server refuses a crafted bar line too.
     */
    public function barEnabled(): bool
    {
        $location = $this->resolveLocation();

        return $location !== null && (bool) Settings::get('bar_enabled', true, $location->id) && $this->userCan('pos.bar');
    }

    /** Prompt 292 — does THIS sede offer the € calculator? Off by default (the owner's decision), per sede. */
    public function calculatorEnabled(): bool
    {
        return (bool) Settings::get('dispensary_calculator_enabled', false, $this->locationId);
    }

    /**
     * Prompt 292 — the keypad runs in the browser, so the typed value (and whether it is grams or euros) arrives HERE,
     * with the tap on "Añadir a la cesta", and is validated exactly as a typed amount always was. `$mode` is ignored when
     * the sede's calculator is off: the value is grams. Both arguments are optional for callers that set the fields.
     */
    public function addLine(?string $value = null, ?string $mode = null): void
    {
        if ($value !== null) {
            $this->weightInput = $value;
        }
        if ($mode !== null) {
            $this->calculatorMode = $mode === 'calculator';
        }
        if (! $this->calculatorEnabled()) {
            $this->calculatorMode = false;
        }

        $member = $this->resolveMember();
        $location = $this->resolveLocation();

        if ($member === null || $location === null || $this->activeGeneticId === null) {
            return;
        }

        $genetic = Genetic::query()->find($this->activeGeneticId);

        if ($genetic === null) {
            $this->flash(__('Genética no disponible.'), 'error');

            return;
        }

        // Prompt 250 — automatic mode chooses no lote here: the line carries a null batch_id and the sede's
        // batches are drawn oldest-first at commit. It still refuses BEFORE the basket when there is nothing to
        // draw from — the sede's dispensable total (units for UNIT, grams for WEIGHT). Manual mode is unchanged:
        // the chosen lote, refused unless dispensable (open, in stock, not expired).
        if ($this->automaticBatches()) {
            $hasStock = $genetic->isUnitType()
                ? $this->remainingUnits($genetic, $location) > 0
                : $this->remainingCg($genetic, $location) > 0;

            if (! $hasStock) {
                $this->flash(__('No hay stock disponible para dispensar (agotado o caducado).'), 'error');

                return;
            }

            $batchId = null;
        } else {
            $batch = $this->activeBatchId !== null
                ? Batch::query()->withoutGlobalScopes()->find($this->activeBatchId)
                : null;

            if ($batch === null || ! (new SelectBatch)->isDispensable($batch)) {
                $this->flash(__('No hay lote disponible para dispensar (agotado o caducado).'), 'error');

                return;
            }

            $batchId = $batch->id;
        }

        // UNIT genetic → the stepper drives whole units; grams_cg is computed. WEIGHT → grams pad.
        if ($genetic->isUnitType()) {
            $units = max(1, $this->unitQty);
            $line = [
                'genetic_id' => $genetic->id,
                'batch_id' => $batchId,
                'grams_cg' => $units * (int) $genetic->grams_per_unit_cg,
                'units' => $units,
            ];
        } else {
            $gramsCg = $this->resolveGramsCg($genetic, $location);

            if ($gramsCg === null || $gramsCg <= 0) {
                $this->flash(__('Introduce un peso válido.'), 'error');

                return;
            }

            $line = [
                'genetic_id' => $genetic->id,
                'batch_id' => $batchId,
                'grams_cg' => $gramsCg,
                'units' => null,
            ];
        }

        $this->basket[] = $line;
        $this->forgetLastSale();

        if ($this->idempotencyKey === null) {
            $this->idempotencyKey = (string) Str::ulid();
        }

        // A new line invalidates any prior limit-breach state — re-evaluated on next commit.
        $this->reset(['activeGeneticId', 'activeBatchId', 'weightInput', 'calculatorMode', 'unitQty', 'requireOverride', 'limitBreach']);
    }

    public function removeLine(int $index): void
    {
        unset($this->basket[$index]);
        $this->basket = array_values($this->basket);
        $this->requireOverride = false;
        $this->limitBreach = false;
    }

    public function clearBasket(): void
    {
        $this->reset([
            'basket', 'activeGeneticId', 'activeBatchId', 'weightInput', 'calculatorMode', 'unitQty',
            'cashTendered', 'walletInput', 'requireOverride', 'limitBreach', 'overrideReason',
            'priceOverrideEuros', 'priceOverrideReason', 'signaturePath',
        ]);

        $this->idempotencyKey = (string) Str::ulid();
    }

    // --- Signature (only when the location requires it) -------------------------

    public function saveSignature(string $dataUrl): void
    {
        $prefix = 'data:image/png;base64,';

        if (! str_starts_with($dataUrl, $prefix)) {
            return;
        }

        $binary = base64_decode(substr($dataUrl, strlen($prefix)), true);

        if ($binary === false) {
            return;
        }

        $path = 'signatures/'.Str::ulid().'.png';
        // Encrypted at rest through the vault (prompt 113) — never plaintext on the Article-9 disk.
        DocumentVault::put($path, $binary);
        // No flash (prompt 234): the pad replaces itself with "✓ Firma capturada" and a Rehacer control the
        // moment this returns. A toast saying the same thing, in the pinned foot, took height from the basket
        // to repeat what the operator was looking at.
        $this->signaturePath = $path;
    }

    public function clearSignature(): void
    {
        $this->signaturePath = null;
    }

    // --- Commit -----------------------------------------------------------------

    /**
     * NOT `commit()` — that name is unreachable from a browser (prompt 195).
     *
     * Livewire v4's `$wire` proxy resolves an ALIAS TABLE before it looks for a component method, and that
     * table maps `commit` → `$commit`, a built-in state flush that returns null. So `wire:click="commit"`
     * called Livewire's own no-op and this method was never invoked from the counter. Ever. The button was
     * hit-testable, enabled and produced a 200 — it just ran the wrong thing.
     *
     * The 42 tests over this path all call it from PHP (`Livewire::test(...)->call('commit')`), which
     * invokes the method directly and never meets the proxy. They proved the method works, not that the
     * button reaches it.
     */
    public function commitDispensation(): void
    {
        $this->attemptCommit(override: false);
    }

    /**
     * "Añadir a la cuenta" was chosen for this sale (prompt 259). Set only by {@see commitOnTab()} and cleared with
     * the basket; the writers re-check the member's approval and headroom, so a forged `true` gains nothing a
     * member without an approved tab could use.
     */
    public bool $onTab = false;

    /** What the member hands over to pay down what they owe here (euros; blank = all of it). */
    public string $debtCollectInput = '';

    /**
     * Put the part of this sale the member is not paying today on their TAB (prompt 259) — the deliberate act the
     * owner asked for, never a side effect of an empty wallet. The unpaid remainder is the total less the cash
     * handed over; it is drawn from the wallet, taking it below zero by exactly the part their credit does not
     * cover. Refused here, with the reason, when the member has no approved tab or the remainder would pass their
     * limit (measured on their debt across every sede) — and refused again by the writers if anything was forged.
     */
    public function commitOnTab(): void
    {
        if (! $this->requireOperator()) { // the pad first — the socio is withheld until someone identifies (post-296 audit)
            return;
        }

        $member = $this->resolveMember();
        $location = $this->resolveLocation();

        if ($member === null || $location === null) {
            $this->flash(__('Identifica a un socio antes de registrar una dispensación.'), 'error');

            return;
        }

        $tab = $this->tabState($member, $location, $this->tenderableTotalCents());

        if ($tab['limit'] <= 0) {
            $this->flash(__('Este socio no tiene una cuenta aprobada.'), 'error');

            return;
        }

        if (! $tab['fits']) {
            $this->flash(__('Supera el límite de deuda aprobado: como mucho :money más en la cuenta.', [
                'money' => $this->money($tab['headroom']),
            ]), 'error');

            return;
        }

        $this->onTab = true;
        $this->walletInput = $this->eurosString($tab['remainder']);

        $this->attemptCommit(override: false); // one path for the whole visit (prompt 263)
    }

    /**
     * Collect what the member owes at this sede (prompt 259's reminder) — a cash TOPUP into their wallet, recorded
     * against the open till so the arqueo expects it (`TillSummary` counts till-attributed top-ups). The same
     * single wallet writer as every other movement; nothing new writes money.
     */
    public function collectDebt(): void
    {
        if (! $this->requireOperator()) {
            return;
        }

        if (! $this->userCan('pos.use')) {
            $this->flash(__('No tienes permiso para cobrar.'), 'error');

            return;
        }

        $member = $this->resolveMember();
        $location = $this->resolveLocation();

        if ($member === null || $location === null) {
            return;
        }

        $owedHere = max(0, -Wallet::balance($member->id, $location->id));
        $cents = trim($this->debtCollectInput) === '' ? $owedHere : $this->parseCents($this->debtCollectInput);

        if ($cents === null || $cents <= 0) {
            $this->flash(__('Introduce un importe válido.'), 'error');

            return;
        }

        $till = $this->openTillSession($location);

        if ($till === null) {
            $this->flash(__('No hay caja abierta en este terminal.'), 'error');

            return;
        }

        (new RecordWalletTransaction)->handle($member, $location, $cents, WalletTransactionType::TOPUP, [
            'operator_id' => CounterOperator::id(),
            'till_session_id' => $till->id,
            'reason' => 'Pago de deuda en el mostrador',
        ]);

        $this->debtCollectInput = '';
        $this->flash(__('Cobrado :money a cuenta de la deuda.', ['money' => $this->money($cents)]), 'success');
    }

    /**
     * The member's tab, as the screen and {@see commitOnTab()} read it: the approved limit, what they owe in total
     * (every sede), the headroom left, the part of `$total` the cash handed over does not cover, and whether that
     * part fits (after spending any credit they hold here).
     *
     * @return array{limit: int, owed: int, headroom: int, remainder: int, fits: bool}
     */
    private function tabState(Member $member, Location $location, int $total): array
    {
        $tendered = trim($this->cashTendered) === '' ? 0 : max(0, $this->parseCents($this->cashTendered) ?? 0);
        $remainder = max(0, $total - $tendered);

        return [
            'limit' => (int) ($member->debt_limit_cents ?? 0),
            'owed' => Wallet::totalDebtCents($member->id),
            'headroom' => Wallet::tabHeadroomCents($member, $location->id),
            'remainder' => $remainder,
            // The wallet writer's own question (prompt 273) — credit here plus the tab's headroom — not a hand copy of it.
            'fits' => $remainder > 0 && $remainder <= Wallet::maxDebitCents($member, $location->id),
        ];
    }

    public function commitWithOverride(): void
    {
        $this->attemptCommit(override: true);
    }

    /** The PIN of whoever authorises a limit breach for the staff member serving (prompt 265). Never persisted. */
    public string $authoriserPin = '';

    /**
     * "Autorizar con PIN" (prompt 265): a manager standing beside the staff member authorises THIS breach with their
     * own PIN and a reason — the dispensation commits with the manager as the override's authoriser while the staff
     * member stays the operator of record and stays identified. Audited as the manager's override
     * (`dispensation.limit.override`, authorised_by). A PIN without `limits.override` is refused.
     */
    public function commitWithAuthoriserPin(): void
    {
        $pin = $this->authoriserPin;
        $this->authoriserPin = ''; // never keep a PIN in component state

        if (trim($this->overrideReason) === '') {
            $this->flash(__('Indica el motivo de la excepción (queda registrado).'), 'error');

            return;
        }

        $authoriser = $this->authoriserFromPin($pin, 'limits.override');

        if ($authoriser === null) {
            return;
        }

        $this->attemptCommit(override: true, authoriser: $authoriser);
    }

    private function attemptCommit(bool $override, ?User $authoriser = null): void
    {
        // Attribution: a PIN-identified operator is required — never the device session user. FIRST: with nobody at the
        // PIN the socio is withheld (post-296 audit), so the pad must open before "identify a socio" could be the answer.
        if (! $this->requireOperator()) {
            return;
        }

        $member = $this->resolveMember();
        $location = $this->resolveLocation();

        // The "no cannabis line without a member" rule — enforced here, asserted in the tests.
        if ($member === null || $location === null) {
            $this->flash(__('Identifica a un socio antes de registrar una dispensación.'), 'error');

            return;
        }

        if (! $this->checkInSatisfied($member, $location)) {
            return;
        }

        // Fail closed: a commit made offline cannot be trusted (limits/stock/balances are live-query).
        if ($this->offline) {
            $this->flash(__('Sin conexión: no se puede registrar. La cesta se conserva hasta reconectar.'), 'error');

            return;
        }

        if ($this->basket === [] && $this->barBasket === []) {
            $this->flash(__('La cesta está vacía.'), 'error');

            return;
        }

        // Prompt 266 — each half of the visit is the operator's to charge: the aportación needs `pos.use`, the bar lines
        // `pos.bar`. Refused with the basket INTACT — bar items already in the visit (the permission revoked mid-shift)
        // are neither charged silently nor dropped silently.
        if ($this->basket !== [] && ! $this->userCan('pos.use')) {
            $this->flash(__('Tu usuario no puede registrar dispensaciones.'), 'error');

            return;
        }

        if ($this->barBasket !== [] && ! $this->userCan('pos.bar')) {
            $this->flash(__('Hay productos de barra en la visita: los cobra alguien con permiso de barra.'), 'error');

            return;
        }

        // Prompt 263 — ONE pay button settles the whole visit. A visit with only bar lines is a bar order on its
        // own ledger; no dispensation, so none of the dispensation's gates below apply to it.
        if ($this->basket === []) {
            $this->settleBarOnly($member, $location);

            return;
        }

        // Counter eligibility — the SAME shared resolver as the door. A BLOCK-mode failure
        // hard-stops here (CommitDispensation independently re-checks membership + carencia).
        $verdict = (new ResolveMemberEligibility)->handle($member, $location, 'counter');

        if ($this->hardBlockRules($verdict) !== []) {
            $this->flash(
                __('Dispensación bloqueada: :reasons', ['reasons' => implode(' · ', $verdict->blockingMessages())]),
                'error',
            );

            return;
        }

        // An OVERRIDE-mode block (or a prior limit breach) needs a reasoned, permissioned override.
        if (! $override && ($this->overridableRules($verdict) !== [] || $this->limitBreach)) {
            $this->requireOverride = true;
            $this->flash(__('Se requiere la autorización de un responsable para continuar.'), 'warning');

            return;
        }

        // Signature, when the location mandates one for a dispensation.
        if ($this->signatureRequired() && $this->signaturePath === null) {
            $this->flash(__('Falta la firma del socio.'), 'warning');

            return;
        }

        // Cash contributions attach to the OPEN till session; no open caja ⇒ no commit.
        $till = $this->openTillSession($location);

        if ($till === null) {
            $open = $this->openTerminalNames($location);
            $this->flash($open === ''
                ? __('No hay caja abierta en este terminal.')
                : __('No hay caja abierta en este terminal. Con caja abierta: :terminals', ['terminals' => $open]), 'error');

            return;
        }

        $resolvedTotal = $this->basketTotalCents($member, $location);
        $total = $resolvedTotal;

        // Price override (prompt 64): a permission holder may charge LESS than the resolved price (comp a
        // member for defective product, or give it free) with a mandatory reason. It changes the charged
        // total ONLY — eligibility + limits were already enforced above. The reason panel reuses the same
        // interaction as the limit override.
        $priceOverrideCents = null;
        if (trim($this->priceOverrideEuros) !== '') {
            $user = $this->counterActor();

            if ($user === null || ! $user->can('dispensation.price.override')) {
                $this->flash(__('No tienes permiso para ajustar el precio.'), 'error');

                return;
            }

            // Prompt 333 — a holder of `reasons.optional` (a manager, by default) may leave it: the reason stored is
            // "Aprobado por responsable", and the dispensation and the audit still name them. Everyone else types one.
            if (trim($this->priceOverrideReason) === '') {
                if (! ManagerApproval::allows($user)) {
                    $this->flash(__('Indica el motivo del ajuste de precio (queda registrado).'), 'error');

                    return;
                }
                $this->priceOverrideReason = ManagerApproval::reason();
            }

            // Parse through the shared validating helper: a non-numeric entry is REJECTED, never silently coerced to
            // 0 (which, permission + reason aside, would be a free dispensation). The same check the screen shows (333).
            if ($this->priceOverrideEntered() === null) {
                $this->flash(__('El precio ajustado no es válido.'), 'error');

                return;
            }

            $priceOverrideCents = $this->chargeableCents($resolvedTotal); // reduce only: 0 (free) .. resolved
            $total = $priceOverrideCents;
        }

        // Prompt 263 — the tender covers the WHOLE visit: the (possibly overridden) aportación plus the bar lines,
        // one payment. The wallet pays the aportación first, then the bar — the same split the combined settle
        // always used — and each ledger records its own share.
        $barTotal = $this->barBasket !== [] ? $this->barBasketTotalCents($member, $location) : 0;
        [$cashApplied, $walletApplied] = $this->tenderSplit($total + $barTotal);

        // The split is DERIVED (cash = total − wallet) so it always reconciles — the fix for prompt 74: the
        // Cash field is what the member HANDED, not the charge. The only tender error is an UNDER-tender
        // (handed less cash than owed); over-tender is fine and produces change. Tender is measured against
        // $total, which is already the OVERRIDDEN total when a price override is applied (prompt 64).
        if ($this->isUnderTendered($cashApplied)) {
            $this->flash(__('El efectivo entregado no cubre el total.'), 'error');

            return;
        }

        $walletCents = min($walletApplied, $total);
        $cashCents = $total - $walletCents;
        $barWallet = $walletApplied - $walletCents;

        $options = [
            'operator_id' => CounterOperator::id(),
            'till_session_id' => $till->id,
            'cash_cents' => $cashCents,
            'wallet_cents' => $walletCents,
            'idempotency_key' => $this->idempotencyKey,
            'on_tab' => $this->onTab, // prompt 259 — only via "Añadir a la cuenta", and only within the tab
        ];

        if ($this->signaturePath !== null) {
            $options['signature_path'] = $this->signaturePath;
        }

        if ($priceOverrideCents !== null) {
            $options['price_override_cents'] = $priceOverrideCents;
            $options['price_override_reason'] = trim($this->priceOverrideReason);
            $options['price_override_by'] = $this->counterActor();
        }

        if ($override) {
            // The authoriser: the operator, or — "Autorizar con PIN" (prompt 265) — whoever's PIN authorised it.
            $user = $authoriser ?? $this->counterActor();

            if ($user === null || ! $user->can('limits.override')) {
                $this->flash(__('No tienes permiso para autorizar una excepción.'), 'error');

                return;
            }

            $reason = trim($this->overrideReason);

            if ($reason === '') {
                $this->flash(__('Indica el motivo de la excepción (queda registrado).'), 'error');

                return;
            }

            $options['override'] = true;
            $options['override_by'] = $user;
            $options['override_reason'] = $reason;
        }

        // Pass units for UNIT lines and grams_cg for WEIGHT lines; CommitDispensation
        // recomputes the stored grams_cg for UNIT lines from the genetic (authoritative).
        $lines = array_map(fn (array $line): array => [
            'genetic_id' => (string) $line['genetic_id'],
            // Null in automatic mode (prompt 250) — CommitDispensation allocates the lote(s) at commit.
            'batch_id' => $line['batch_id'] !== null ? (string) $line['batch_id'] : null,
            'grams_cg' => (int) $line['grams_cg'],
            'units' => $line['units'] !== null ? (int) $line['units'] : null,
        ], $this->basket);

        // Prompt 263 — with bar lines waiting, the dispensation and the bar order are written TOGETHER (atomic,
        // two ledgers) — never the dispensation alone with the drinks left behind unpaid.
        if ($this->barBasket !== []) {
            $this->commitVisit($member, $location, $lines, $options, $barTotal, $barWallet);

            return;
        }

        try {
            $dispensation = (new CommitDispensation)->handle($member, $location, $lines, $options);
        } catch (LimitExceededException) {
            // Offer the reasoned, permissioned override (CommitDispensation authorises it).
            $this->limitBreach = true;
            $this->requireOverride = true;
            $this->flash(__('Supera el límite de consumo. Se requiere autorización con motivo.'), 'warning');

            return;
        } catch (DispensationBlockedException $e) {
            $this->flash($e->getMessage(), 'error');

            return;
        } catch (TillClosedException) {
            $this->flash(__('La caja no está abierta.'), 'error');

            return;
        } catch (AuthorizationException) {
            $this->flash(__('No tienes permiso para autorizar una excepción.'), 'error');

            return;
        } catch (DebtLimitExceededException $e) {
            // Prompt 259 — the wallet did not cover it and the tab was not chosen (or would pass the limit).
            $this->flash($e->getMessage(), 'error');

            return;
        } catch (StockUnavailableException $e) {
            $this->flash($e->getMessage(), 'error'); // names the product and what is left (273)

            return;
        } catch (RuntimeException) {
            $this->flash(__('No se pudo registrar la dispensación. Revisa la cesta y el stock.'), 'error');

            return;
        }

        // Before the reset — see BarPos: the change comes from cashTendered, which the reset clears.
        $change = $this->changeDueCents($dispensation->cash_cents->cents);

        $this->lastDispensationId = $dispensation->id;
        $this->resetBasketState();
        $this->flashSettled(SettledOutcome::forDispensation($dispensation, $change), __('Dispensación registrada.'));
    }

    // --- Combined settle: same visit, cannabis + bar, two records (prompt 118) --------

    /** Add a bar/merch article to the visit's bar side. Articles only — a genetic can never appear here. */
    public function addBarItem(string $articleId): void
    {
        $location = $this->resolveLocation();
        if ($location === null) {
            return;
        }

        // Prompt 266 — the hidden Barra switch was the only gate; a crafted call added the line anyway.
        if (! $this->userCan('pos.bar')) {
            $this->flash(__('Tu usuario no puede vender en la barra.'), 'error');

            return;
        }

        $article = Article::query()->where('location_id', $location->id)->find($articleId);
        if ($article === null || ! $article->active) {
            return;
        }

        $existing = null;
        foreach ($this->barBasket as $i => $line) {
            if (isset($line['article_id']) && $line['article_id'] === $articleId) {
                $existing = $i;
                break;
            }
        }

        // The disabled attribute on the card is presentation; THIS is the rule (prompt 230). Sold-out
        // articles are VISIBLE on this screen now, so the refusal has to exist and has to say why — a gate
        // that is only a picture is not a gate. Same figure and same wording as the standalone Bar's.
        $inBasket = $existing !== null ? (int) $this->barBasket[$existing]['qty'] : 0;
        if ($inBasket + 1 > (int) $article->stock) {
            $this->flash(__('No hay stock suficiente de :name.', ['name' => $article->name]), 'warning');

            return;
        }

        if ($existing !== null) {
            $this->barBasket[$existing]['qty']++;

            return;
        }

        $this->barBasket[] = ['article_id' => $articleId, 'qty' => 1];
        $this->forgetLastSale();
        $this->dismissOutcome();
    }

    public function removeBarItem(int $index): void
    {
        unset($this->barBasket[$index]);
        $this->barBasket = array_values($this->barBasket);
    }

    /**
     * Prompt 331 — the Bar screen's manual line on the visit's bar side: the same modal and the same rules
     * ({@see AddsManualBarLines}). A bar/shop line only; cannabis always names a batch and grams.
     */
    public function addMiscLine(): void
    {
        $line = $this->takeManualLine();
        if ($line === null) {
            return;
        }

        $this->barBasket[] = $line;
        $this->forgetLastSale();
        $this->dismissOutcome();
    }

    /**
     * Write a dispensation AND its bar order in one atomic settle (prompt 118's `CommitCombinedSettle`, two
     * ledgers), reached from {@see attemptCommit()} after every dispensation gate has passed — the price override
     * and the limit override ride in the dispensation's own options.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $dispOptions
     */
    private function commitVisit(Member $member, Location $location, array $lines, array $dispOptions, int $barTotal, int $barWallet): void
    {
        $orderLines = $this->barOrderLines();

        try {
            $result = (new CommitCombinedSettle)->handle($member, $location, $lines, $orderLines, [
                'till_session_id' => $dispOptions['till_session_id'],
                'operator_id' => $dispOptions['operator_id'],
                'on_tab' => $this->onTab,
                'dispensation' => $dispOptions,
                'order' => [
                    'cash_cents' => $barTotal - $barWallet,
                    'wallet_cents' => $barWallet,
                    'idempotency_key' => $this->idempotencyKey !== null ? $this->idempotencyKey.'-bar' : null,
                ],
            ]);
        } catch (DebtLimitExceededException $e) {
            $this->flash($e->getMessage(), 'error');

            return;
        } catch (DispensationBlockedException $e) {
            $this->flash($e->getMessage(), 'error');

            return;
        } catch (LimitExceededException) {
            $this->limitBreach = true;
            $this->requireOverride = true;
            $this->flash(__('Supera el límite de consumo. Se requiere autorización con motivo.'), 'warning');

            return;
        } catch (TillClosedException) {
            $this->flash(__('La caja no está abierta.'), 'error');

            return;
        } catch (AuthorizationException) {
            $this->flash(__('No tienes permiso para autorizar una excepción.'), 'error');

            return;
        } catch (StockUnavailableException $e) {
            $this->flash($e->getMessage(), 'error');

            return;
        } catch (RuntimeException) {
            $this->flash(__('No se pudo liquidar la visita. Revisa las cestas y el stock.'), 'error');

            return;
        }

        $this->lastDispensationId = $result['dispensation']->id;
        $this->lastOrderId = $result['order']->id;
        $this->resetBasketState();
        $this->barBasket = [];
        $this->flash(__('Visita liquidada: dispensación y barra.'), 'success');
    }

    /**
     * A visit with nothing but bar lines: one Order, on the bar ledger, through `CommitOrder` — the same
     * single writer the Barra screen calls (prompt 224).
     *
     * Reached only from {@see self::attemptCommit()} (prompt 263), after its operator/check-in/offline guards. Before 224 this
     * case could not be settled at all: the combined settle required both baskets, so an operator who had
     * added three soft drinks to a member's visit had to add a flower line to take the money.
     */
    private function settleBarOnly(Member $member, Location $location): void
    {
        // The bar shares the one drawer, so a sale still needs an OPEN till — resolved here rather than
        // inherited, because the combined path resolves it after checks this case skips.
        $till = $this->openTillSession($location);

        if ($till === null) {
            $this->flash(__('No hay caja abierta en este terminal.'), 'error');

            return;
        }

        $barTotal = $this->barBasketTotalCents($member, $location);
        [$cashApplied, $walletApplied] = $this->tenderSplit($barTotal);

        if ($this->isUnderTendered($cashApplied)) {
            $this->flash(__('El efectivo entregado no cubre el total.'), 'error');

            return;
        }

        $orderLines = $this->barOrderLines();

        try {
            $order = (new CommitOrder)->handle($location, $orderLines, [
                'member_id' => $member->id,
                'till_session_id' => $till->id,
                'operator_id' => CounterOperator::id(),
                'cash_cents' => $cashApplied,
                'wallet_cents' => $walletApplied,
                'idempotency_key' => $this->idempotencyKey !== null ? $this->idempotencyKey.'-bar' : null,
                'on_tab' => $this->onTab,
            ]);
        } catch (DebtLimitExceededException $e) {
            // The writer's own words (259: "solo se puede añadir a la cuenta…"), as the other two paths show (273).
            $this->flash($e->getMessage(), 'error');

            return;
        } catch (TillClosedException) {
            $this->flash(__('La caja no está abierta.'), 'error');

            return;
        } catch (RuntimeException) {
            $this->flash(__('No se pudo cobrar la barra. Revisa la cesta y el stock.'), 'error');

            return;
        }

        $this->lastOrderId = $order->id;
        $this->barBasket = [];
        // The same reset the combined settle uses — it clears the tender fields as well as the basket, and
        // mints the next idempotency key.
        $this->resetBasketState();
        $this->flash(__('Barra cobrada.'), 'success');
    }

    /** The charged bar total, priced through the SAME resolver CommitOrder uses so the tender matches. */
    private function barBasketTotalCents(?Member $member, ?Location $location): int
    {
        if ($location === null || $this->barBasket === []) {
            return 0;
        }

        $discounter = new ResolveArticleDiscount;
        $bp = $member !== null ? $discounter->bpFor($member, $location) : 0;
        $total = 0;

        foreach ($this->barBasket as $line) {
            if (! isset($line['article_id'])) { // a manual line: its own amount, never member-discounted (as on the Bar)
                $total += (int) $line['unit_price_cents'] * max(1, (int) $line['qty']);

                continue;
            }
            $article = Article::query()->where('location_id', $location->id)->find($line['article_id']);
            if ($article === null) {
                continue;
            }
            $gross = $article->price_cents->cents * max(1, (int) $line['qty']);
            $total += $gross - $discounter->discountCents($gross, $bp);
        }

        return $total;
    }

    /**
     * The bar basket in `CommitOrder`'s line shapes — catalogue lines, and manual lines exactly as the Bar screen sends
     * them (prompt 331).
     *
     * @return list<array{article_id?: string, description?: string, unit_price_cents?: int, qty: int, reference?: string}>
     */
    private function barOrderLines(): array
    {
        return array_map(fn (array $l): array => isset($l['article_id'])
            ? ['article_id' => (string) $l['article_id'], 'qty' => (int) $l['qty']]
            : ['description' => (string) $l['description'], 'unit_price_cents' => (int) $l['unit_price_cents'], 'qty' => (int) $l['qty'], 'reference' => (string) $l['reference']],
            $this->barBasket);
    }

    // --- Void -------------------------------------------------------------------

    public function voidLast(): void
    {
        if ($this->lastDispensationId === null) {
            return;
        }

        // A void is a reversal WRITE, so it needs a PIN-identified operator too — this is also what makes the
        // idle lock (prompt 120) refuse it server-side, since locking signs the operator out.
        if (! $this->requireOperator()) {
            return;
        }

        $user = $this->counterActor();

        if ($user === null || ! $user->can('dispensation.void')) {
            $this->flash(__('No tienes permiso para anular una dispensación.'), 'error');

            return;
        }

        $reason = trim($this->voidReason);

        if ($reason === '') {
            $this->flash(__('Indica el motivo de la anulación (queda registrado).'), 'error');

            return;
        }

        $dispensation = $this->lastDispensation();

        if ($dispensation === null) {
            $this->flash(__('Dispensación no encontrada.'), 'error');

            return;
        }

        try {
            (new VoidDispensation)->handle($dispensation, $user, $reason);
        } catch (AuthorizationException) {
            $this->flash(__('No tienes permiso para anular una dispensación.'), 'error');

            return;
        } catch (RuntimeException) {
            $this->flash(__('No se pudo anular la dispensación.'), 'error');

            return;
        }

        $this->voidReason = '';
        $this->lastDispensationId = null;
        $this->flash(__('Dispensación anulada. Stock y monedero revertidos.'), 'success');
    }

    /** Email the just-committed receipt to the socio (prompt 56) — worded as an aportación. */
    public function emailReceipt(): void
    {
        if (TrainingMode::active()) { // prompt 324 — a practice receipt is never emailed
            $this->flash(__('No disponible en modo formación'), 'error');

            return;
        }
        if ($this->lastDispensationId === null) {
            return;
        }

        // Sending a member's receipt is counter work like any other (prompt 255): someone must be identified —
        // after an idle lock, whoever is standing at the tablet cannot mail a member's contribution out.
        if (! $this->requireOperator()) {
            return;
        }

        $dispensation = $this->lastDispensation()?->load(['member', 'lines']);
        $email = $dispensation?->member?->email;

        if ($dispensation === null || $email === null) {
            $this->flash(__('El socio no tiene correo electrónico.'), 'error');

            return;
        }

        // Queued, best-effort (prompt 149): a mail failure at the counter must be a readable message, not
        // Livewire's error screen mid-service. Delivery problems belong in Horizon's failed jobs.
        try {
            Mail::to($email)
                ->locale((new ResolveLocale)->handle($dispensation->member))
                ->queue(DispensationReceiptMail::fromDispensation($dispensation));
            $this->flash(__('Comprobante enviado al socio (en cola).'), 'success');
        } catch (\Throwable) {
            $this->flash(__('No se pudo enviar el comprobante. Inténtalo de nuevo.'), 'error');
        }
    }

    /**
     * Prompt 300 — the after-sale line goes with the next thing that happens (a line added, a member changed). It used to
     * clear only on a void, so it stayed through the next member's whole visit. Voiding later is the panel's job.
     */
    private function forgetLastSale(): void
    {
        $this->lastDispensationId = null;
        $this->lastOrderId = null;
        $this->voidReason = '';
    }

    /**
     * The after-sale line (prompt 300): "Última: 15,00 € · 1,00 g · 14:02", and whether the socio has an address to email.
     *
     * @return array{summary: string, emailable: bool}|null
     */
    protected function lastSale(): ?array
    {
        $dispensation = $this->lastDispensationId !== null ? $this->lastDispensation()?->load('member') : null;
        if ($dispensation === null) {
            return null;
        }

        return [
            'summary' => __('Última: :total · :grams · :time', [
                'total' => $dispensation->total_cents->formatted(),
                'grams' => Weight::fromCentigrams($dispensation->dispensedGramsCg())->formatted(),
                'time' => local_datetime($dispensation->created_at, 'H:i', $this->resolveLocation()),
            ]),
            'emailable' => filled($dispensation->member?->email),
        ];
    }

    /**
     * The contribution this counter just committed — scoped to THIS sede (prompt 255, audit F5). The id is a
     * public property the client can set, so `withoutGlobalScopes()->find()` alone found ANY organisation's
     * dispensation for a void or a receipt e-mail. Scoped to the counter's #[Locked] sede, the reach is what the
     * operator could already do here; the void keeps its own permission check on top.
     */
    private function lastDispensation(): ?Dispensation
    {
        // The sede is the tighter bound (a location belongs to one organisation, and `$locationId` is #[Locked]).
        return Dispensation::query()->withoutGlobalScopes()
            ->where('location_id', $this->locationId)
            ->find($this->lastDispensationId);
    }

    // --- View data (assembled here; the view stays declarative) -----------------

    public function render(): View
    {
        $location = $this->resolveLocation();
        $member = $this->resolveMember();

        $verdict = null;
        $limits = null;
        $membership = null;
        $walletCents = 0;
        $openTill = $location !== null ? $this->openTillSession($location) : null;

        if ($member !== null && $location !== null) {
            $verdict = (new ResolveMemberEligibility)->handle($member, $location, 'counter');
            $limits = (new ResolveMemberLimits)->shown($member, $location); // none while limits are off (296)
            $membership = $this->activeMembership($member, $location);
            $walletCents = Wallet::balance($member->id, $location->id);
        }

        $basketLines = $this->basketView($member, $location);
        $resolvedTotal = (int) array_sum(array_map(fn (array $l): int => (int) $l['total_cents'], $basketLines));
        $total = $this->chargeableCents($resolvedTotal);

        // The tender preview is split over the COMBINED total (prompt 224). It used to split the dispensation
        // total alone while the combined settle split dispensation + bar, so with a bar line present the
        // breakdown above the settle button disagreed with the settle button itself — and a bar-only visit had
        // a breakdown reading €0,00 against real money. One figure, one split, one place.
        $barTotal = $this->barBasketTotalCents($member, $location);
        [$cashPreview, $walletPreview] = $this->tenderSplit($total + $barTotal);

        $activeGeneticModel = $this->activeGeneticId !== null ? Genetic::query()->find($this->activeGeneticId) : null;

        return view('livewire.counter.dispensary-pos', [
            'location' => $location,
            'member' => $member,
            'verdict' => $verdict,
            'limits' => $limits,
            'membership' => $membership,
            'sanction' => $member !== null ? $this->activeSanction($member) : null,
            'walletCents' => $walletCents,
            'projectedWalletCents' => $walletCents - $walletPreview,
            // Prompt 259 — the owe reminder (any debt, anywhere, whatever the toggles) and the tab.
            'owesCents' => $member !== null ? Wallet::totalDebtCents($member->id) : 0,
            'owesHereCents' => max(0, -$walletCents),
            'tab' => $member !== null && $location !== null ? $this->tabState($member, $location, $total + $barTotal) : null,
            'photoUrl' => $member !== null ? $this->photoUrl($member) : null,
            'activeEntryGramsCg' => $this->activeEntryGramsCg(),
            'basketLines' => $basketLines,
            'basketTotalCents' => $total,
            'priceOverrideNotice' => $this->priceOverrideNotice($resolvedTotal), // prompt 333
            'reasonOptional' => ManagerApproval::allows(CounterOperator::current()), // prompt 333 — "Aprobado por responsable"
            'visitTotalCents' => $total + $barTotal, // prompt 263 — the ONE figure on the header, the tender and the button
            'cashPreviewCents' => $cashPreview,
            'walletPreviewCents' => $walletPreview,
            'changeDueCents' => $this->changeDueCents($cashPreview),
            'shortfallCents' => $this->shortfallCents($cashPreview), // prompt 268 — "Falta"
            'activeGenetic' => $activeGeneticModel,
            'weightPresets' => $this->weightPresets($activeGeneticModel, $location, $member, $limits),
            'activeGeneticBatches' => $this->activeGeneticBatches($location),
            'activeGeneticPriceCents' => $this->activeGeneticRateCents($location, $member),
            'calculatorEnabled' => $this->calculatorEnabled(), // prompt 292
            'openTill' => $openTill,
            'requireSignature' => $this->signatureRequired(),
            'requireCheckedIn' => $this->checkedInRequired(),
            'cardReadersEnabled' => $this->cardReadersEnabled(), // prompt 299 — the wedge catcher, with the search hidden
            'hardBlockRules' => $verdict !== null ? $this->hardBlockRules($verdict) : [],
            'overridableRules' => $verdict !== null ? $this->overridableRules($verdict) : [],
            'canOverride' => $this->userCan('limits.override'),
            'canVoid' => $this->userCan('dispensation.void'),
            'lastSale' => $this->lastSale(),
            // Inline fee (prompt 127): the collect action follows the unpaid-fee verdict onto the POS card.
            'canCollectFee' => $this->userCan('membership.fee.collect'),
            'feeOwedCents' => $membership !== null ? $this->owedCents($membership) : 0,
            'openTillPresent' => $openTill !== null,
            // Bar side of the same visit (prompt 118) — only where the sede runs a bar. barArticles feeds the
            // quick-add; barLines + barTotalCents render the in-progress bar basket.
            'barEnabled' => $this->barEnabled(), // sede runs a bar AND the operator may sell at it (prompt 266)
            'barLines' => $this->barBasketView($location),
            'barTotalCents' => $barTotal,
        ]);
    }

    /**
     * The islands' data (prompt 293). The catalogue's three — the pane's `header` (tab, search, filters), and each
     * source's cards — carry BOTH sources in full: the tab, the filters and the search run in the browser over what is
     * already on the page, so they only change what is visible, never what is sold or at what price. The `photo` nag
     * is the socio's, and goes when a photo arrives.
     *
     * @return array<string, mixed>
     */
    protected function islandData(string $island): array
    {
        if ($island === 'photo') {
            $member = $this->resolveMember();

            return ['memberId' => $member?->id, 'missing' => $member !== null && $this->photoUrl($member) === null];
        }

        return $this->catalogueData()[$island] ?? [];
    }

    /** @var array<string, array<string, mixed>>|null */
    private ?array $catalogueMemo = null;

    /** @return array<string, array<string, mixed>> the catalogue's three islands, built together from one set of rows */
    private function catalogueData(): array
    {
        if ($this->catalogueMemo !== null) {
            return $this->catalogueMemo;
        }

        $location = $this->resolveLocation();
        $member = $this->resolveMember();
        $genetics = $this->geneticRows($location, $member);
        $barEnabled = $this->barEnabled();
        $articles = $barEnabled ? $this->barArticleRows($location) : [];

        return $this->catalogueMemo = [
            'header' => [
                'barEnabled' => $barEnabled,
                // Their dispensing history — only with someone at the PIN (260; the post-296 audit found 293 skipped it).
                'usual' => $this->hasOperator() ? array_map(fn (array $row): array => ['id' => $row['id'], 'name' => $row['name']], $this->usualGenetics($member, $genetics)) : [],
                'categories' => $this->deriveCategories($genetics),
                'productTypes' => $this->deriveProductTypes($genetics),
                'strainTypes' => $this->deriveStrainTypes($genetics),
                'articleCategories' => $this->deriveArticleCategories($articles),
            ],
            'genetics' => [
                'rows' => $genetics,
                'hasMember' => $member !== null,
                'thumbs' => collect($genetics)->contains(fn (array $row): bool => $row['image_url'] !== null),
            ],
            'bar' => [
                'enabled' => $barEnabled,
                'rows' => $articles,
                // 193: the thumbnail column exists only where a picture does — asked ONCE for the sede rather
                // than per card, so a catalogue with no images has no empty column at all.
                'thumbs' => collect($articles)->contains(fn (array $row): bool => filled($row['image_url'] ?? null)),
            ],
        ];
    }

    /**
     * The bar's catalogue, in the same shape the genetics side uses (prompt 212).
     *
     * It returned `id`/`name`/`price_cents` and the cart rendered a chip per row — every active in-stock
     * article at the sede, uncapped, in a narrow column. It now carries what a card needs to be browsable:
     * its category (so the pane's filter works), its price, and its **stock STATE**.
     *
     * A state, not a count: 185's rule. The operator needs to know whether to promise it, and a precise
     * figure invites a race to the counter — the same reasoning that keeps a gram figure off the member menu.
     * `Article::low_stock_threshold` already exists per article and is what decides it.
     *
     * Inactive and out-of-stock are still filtered in the QUERY, so an article that cannot be sold is never
     * offered rather than offered and refused.
     *
     * @return list<array<string, mixed>>
     */
    private function barArticleRows(?Location $location): array
    {
        if ($location === null) {
            return [];
        }

        // SOLD-OUT ARTICLES ARE NOT EXCLUDED (prompt 230). `where('stock', '>', 0)` meant an operator on this
        // screen could not see that the coffee had run out — and therefore could not know to restock it —
        // while the standalone Bar showed it disabled with its count. Two screens, same sede, disagreeing
        // about whether a thing exists. The Bar's semantics were the right ones; this adopts them.
        return Article::query()
            ->where('location_id', $location->id)->where('active', true)
            ->with('category')
            ->orderBy('name')->get()
            ->map(fn (Article $a): array => [
                'id' => $a->id,
                'name' => $a->name,
                'price_cents' => $a->price_cents->cents,
                'price_label' => Money::fromCents($a->price_cents->cents)->formatted(),
                'category_id' => $a->category_id,
                'category_name' => $a->category?->name,
                // A COUNT, on a staff screen (prompt 216). 185's state-word rule is the member menu's.
                'stock' => (int) $a->stock,
                'low_stock' => $a->low_stock_threshold !== null && $a->stock <= $a->low_stock_threshold,
                'image_url' => ArticleImage::url($a),
            ])
            ->all();
    }

    /**
     * The categories present in the bar catalogue — derived from the rows, like the genetics side's.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{id: string, name: string}>
     */
    private function deriveArticleCategories(array $rows): array
    {
        $categories = [];

        foreach ($rows as $row) {
            if ($row['category_id'] !== null && ! isset($categories[$row['category_id']])) {
                $categories[$row['category_id']] = ['id' => (string) $row['category_id'], 'name' => (string) $row['category_name']];
            }
        }

        return array_values($categories);
    }

    /**
     * The in-progress bar basket resolved for display (name + qty + line total, live-priced).
     *
     * @return list<array{index: int, name: string, qty: int, line_total_cents: int, manual: bool}>
     */
    private function barBasketView(?Location $location): array
    {
        if ($location === null) {
            return [];
        }

        $rows = [];
        foreach ($this->barBasket as $index => $line) {
            if (! isset($line['article_id'])) { // prompt 331 — a manual line
                $rows[] = ['index' => $index, 'name' => (string) $line['description'], 'qty' => max(1, (int) $line['qty']),
                    'line_total_cents' => (int) $line['unit_price_cents'] * max(1, (int) $line['qty']), 'manual' => true];

                continue;
            }
            $article = Article::query()->where('location_id', $location->id)->find($line['article_id']);
            if ($article === null) {
                continue;
            }
            $qty = max(1, (int) $line['qty']);
            $rows[] = ['index' => $index, 'name' => $article->name, 'qty' => $qty, 'line_total_cents' => $article->price_cents->cents * $qty, 'manual' => false];
        }

        return $rows;
    }

    /** Grams for display (integer centigrams → 2 dp, locale-aware). */
    public function grams(int $centigrams): string
    {
        return Weight::fromCentigrams($centigrams)->formatted();
    }

    /** Money for display (integer cents), via the shared value object. */
    public function money(int $cents): string
    {
        return Money::fromCents($cents)->formatted();
    }

    /**
     * The gram-equivalent (integer centigrams) of the entry in progress — the weighed
     * grams for a WEIGHT genetic, units × grams_per_unit_cg for a UNIT genetic. Same
     * scale for both, so the compliance gauge gets identical real-time feedback whether
     * the operator weighs grams or steps units. Null when no valid entry is in progress.
     */
    public function activeEntryGramsCg(): ?int
    {
        $genetic = $this->activeGeneticId !== null ? Genetic::query()->find($this->activeGeneticId) : null;

        if ($genetic === null) {
            return null;
        }

        if ($genetic->isUnitType()) {
            return max(1, $this->unitQty) * (int) $genetic->grams_per_unit_cg;
        }

        // Prompt 292 — the SAME resolver the basket uses: in calculator mode the typed euros are back-solved to grams
        // (it used to read them AS grams — €20 at €10/g previewed 20,00 g and "nothing left today").
        $location = $this->resolveLocation();

        return $location !== null ? $this->resolveGramsCg($genetic, $location) : $this->parseGramsCg($this->weightInput);
    }

    // --- Weight / calculator resolution -----------------------------------------

    /**
     * Resolve the entered value to integer centigrams. In calculator mode the operator
     * types euros → we back-solve grams from the per-gram rate, rounded DOWN to 0.01 g
     * (integer division, never a float), so the grams stay authoritative and the typed
     * euros are never stored as the total.
     */
    private function resolveGramsCg(Genetic $genetic, Location $location): ?int
    {
        if (! $this->calculatorMode || ! $this->calculatorEnabled()) {
            return $this->parseGramsCg($this->weightInput);
        }

        $cents = $this->parseCents($this->weightInput);

        if ($cents === null || $cents <= 0) {
            return null;
        }

        try {
            $rateCents = (new ResolvePrice)->forGenetic($genetic, $location, $this->resolveMember())->ratePerGramCents;
        } catch (RuntimeException) {
            return null;
        }

        if ($rateCents <= 0) {
            return null;
        }

        // grams = cents / rate; centigrams = grams × 100 = cents × 100 / rate, floored.
        return intdiv($cents * 100, $rateCents);
    }

    private function parseGramsCg(string $grams): ?int
    {
        if (trim($grams) === '') {
            return null;
        }

        try {
            return Weight::fromGrams($grams)->centigrams;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * What the aportación will actually be charged: the resolved total, or a valid price override clamped to 0..resolved
     * (prompt 64's rule). ONE figure for the header, "Justo", "Añadir a la cuenta" and the commit (prompt 271) — the tab
     * used to be filled from the PRE-override total, so an €8,37 basket overridden to €5 with €3 handed put all €5 on
     * the member's tab and showed the €3 as change. The permission and the reason are still checked at commit.
     */
    private function chargeableCents(int $resolvedTotal): int
    {
        if (trim($this->priceOverrideEuros) === '' || ! $this->userCan('dispensation.price.override')) {
            return $resolvedTotal;
        }

        $entered = $this->priceOverrideEntered();

        return $entered === null ? $resolvedTotal : max(0, min($entered, $resolvedTotal));
    }

    /** The typed adjustment in cents, or null when blank or unparseable — the ONE reading of the field (333). */
    private function priceOverrideEntered(): ?int
    {
        return trim($this->priceOverrideEuros) === '' ? null : $this->parseCents($this->priceOverrideEuros);
    }

    /**
     * Prompt 333 — what the adjustment field says about itself, shown under it: an unparseable entry (the commit refuses
     * it), or one above the resolved total (the adjustment only lowers, so the normal price stands). The totals meanwhile
     * stay at the resolved figure, through {@see chargeableCents()}.
     */
    private function priceOverrideNotice(int $resolvedTotal): ?string
    {
        if (trim($this->priceOverrideEuros) === '') {
            return null;
        }
        $entered = $this->priceOverrideEntered();

        return match (true) {
            $entered === null => __('El precio ajustado no es válido.'),
            $entered > $resolvedTotal => __('El ajuste solo puede bajar el total: se cobra el precio normal.'),
            default => null,
        };
    }

    /** The live visit total the shared HandlesTender model splits/tenders against — price-override-aware (271). */
    protected function tenderableTotalCents(): int
    {
        $location = $this->resolveLocation();

        if ($location === null) {
            return 0;
        }

        // BOTH baskets (prompt 224): "Justo" fills in the cash owed, and what is owed on a visit with a bar
        // line is the combined amount — the same figure the one commit (`attemptCommit()`) splits.
        $member = $this->resolveMember();

        return $this->chargeableCents($this->basketTotalCents($member, $location)) + $this->barBasketTotalCents($member, $location);
    }

    // --- Live view assembly (nothing cached) ------------------------------------

    /**
     * The basket, re-priced LIVE via the one resolver so a mid-basket price change
     * can never desync the shown total from the committed total.
     *
     * @return list<array<string, mixed>>
     */
    private function basketView(?Member $member, ?Location $location): array
    {
        if ($location === null) {
            return [];
        }

        $rows = [];
        $eighthInput = [];
        $resolver = new ResolvePrice;

        foreach ($this->basket as $index => $line) {
            $genetic = Genetic::query()->withoutGlobalScopes()->find($line['genetic_id']);

            if ($genetic === null) {
                continue;
            }

            $units = $line['units'] ?? null;
            $quantity = $units !== null ? (int) $units : (int) $line['grams_cg'];

            // Prompt 278 — price the line from the batches it will draw from: the chosen lote, or the FEFO plan (the same
            // resolver call CommitDispensation makes under its lock), each part at its own batch's price.
            try {
                $chosen = $line['batch_id'] !== null ? Batch::query()->withoutGlobalScopes()->find($line['batch_id']) : null;
                $parts = $chosen !== null
                    ? [['batch' => $chosen, 'qty' => $quantity]]
                    : (new AllocateFromBatches)->preview($genetic, $location, $quantity);
                $priced = $resolver->priceParts($genetic, $location, $member, $parts, $units !== null);
            } catch (RuntimeException) {
                continue;
            }

            $rows[] = [
                'index' => $index,
                'genetic_name' => $genetic->name,
                'grams_cg' => (int) $line['grams_cg'],
                'units' => $units !== null ? (int) $units : null,
                'per_unit' => $units !== null,
                'rate_cents' => $priced['rate_cents'],
                'discount_cents' => $priced['discount_cents'],
                'total_cents' => $priced['total_cents'],
                'label' => $priced['label'],
                'eighth_applied' => false,
                // "parte a 8,00 €/g, parte a 10,00 €/g" — said BEFORE commit, not after (278).
                'split_note' => $priced['mixed'] ? $this->splitNote($priced['parts'], $units !== null) : null,
            ];
            $eighthInput[] = $units !== null
                ? ['grams_cg' => (int) $line['grams_cg'], 'rate_cents' => 0, 'per_gram_total' => $priced['total_cents'], 'eighth_price' => null]
                : ['grams_cg' => (int) $line['grams_cg'], 'rate_cents' => $priced['effective_rate_cents'], 'per_gram_total' => $priced['total_cents'], 'eighth_price' => $priced['eighth_price']];
        }

        // Basket-wide eighth (3.5 g) break (prompt 83) — the SAME resolver call CommitDispensation makes, so
        // the shown total can never desync from the committed one.
        $adjusted = $resolver->applyEighthBreaks($eighthInput);
        foreach ($rows as $i => $row) {
            $rows[$i]['total_cents'] = $adjusted[$i]['total_cents'];
            $rows[$i]['eighth_applied'] = $adjusted[$i]['eighth_applied'];
        }

        return $rows;
    }

    /** @param  list<array{batch: Batch, qty: int, rate_cents: int, total_cents: int, discount_cents: int}>  $parts */
    private function splitNote(array $parts, bool $perUnit): string
    {
        $unit = $perUnit ? __('/ud') : __('/g');

        return __('Parte a :rates', ['rates' => implode(', ', array_map(
            fn (array $part): string => Money::fromCents($part['rate_cents'])->formatted().$unit,
            $parts,
        ))]);
    }

    private function basketTotalCents(?Member $member, ?Location $location): int
    {
        return array_sum(array_map(
            fn (array $l): int => (int) $l['total_cents'],
            $this->basketView($member, $location),
        ));
    }

    /**
     * The weight presets for the CURRENT active genetic + member (component state) — the public entry point the
     * view and tests share; render passes the already-computed locals to the private logic for efficiency.
     *
     * @return list<array{grams_cg: int, label: string, price_cents: ?int, eighth_applied: bool, available: bool}>
     */
    public function quickEntryPresets(): array
    {
        $location = $this->resolveLocation();
        $member = $this->resolveMember();
        $genetic = $this->activeGeneticId !== null ? Genetic::query()->find($this->activeGeneticId) : null;
        $limits = ($member !== null && $location !== null) ? (new ResolveMemberLimits)->shown($member, $location) : null;

        return $this->weightPresets($genetic, $location, $member, $limits);
    }

    /**
     * The current member's "usual" genetics (component state) — the view's entry point. Prompt 260 — not a wire action (the view calls it; Livewire invokes only PUBLIC methods from the browser), and nothing with no operator identified: reads follow 255's rule for writes.
     *
     * @return list<array<string, mixed>>
     */
    protected function theirUsual(): array
    {
        if (! $this->hasOperator()) {
            return [];
        }

        $location = $this->resolveLocation();
        $member = $this->resolveMember();

        return $location !== null ? $this->usualGenetics($member, $this->geneticRows($location, $member)) : [];
    }

    /**
     * The one-tap weight presets for the active WEIGHT genetic, each with its resulting price (INCLUDING the
     * eighth break at 3.5 g) and whether it fits the member's remaining allowance — a preset over the cap is
     * shown unavailable, not refused after the tap.
     *
     * @return list<array{grams_cg: int, label: string, price_cents: ?int, eighth_applied: bool, available: bool}>
     */
    private function weightPresets(?Genetic $genetic, ?Location $location, ?Member $member, ?LimitSnapshot $limits): array
    {
        if ($genetic === null || $location === null || $genetic->isUnitType()) {
            return [];
        }

        $remaining = $limits !== null ? min($limits->dailyRemainingCg(), $limits->monthlyRemainingCg()) : PHP_INT_MAX;

        try {
            $price = (new ResolvePrice)->forGenetic($genetic, $location, $member);
        } catch (RuntimeException) {
            $price = null;
        }

        $presets = [];
        foreach ((array) Settings::get('pos_weight_presets_g', [1, 2, 3.5, 5]) as $g) {
            // Through the one weight rule, integer from here on (prompt 273 — it was a float and round_half_up).
            try {
                $cg = Weight::fromGrams(str_replace(',', '.', (string) $g))->centigrams;
            } catch (InvalidArgumentException) {
                continue;
            }
            if ($cg <= 0) {
                continue;
            }
            [$priceCents, $eighthApplied] = $this->presetPrice($price, $cg);

            $presets[] = [
                'grams_cg' => $cg,
                'label' => rtrim(rtrim(sprintf('%d,%02d', intdiv($cg, 100), $cg % 100), '0'), ','),
                'price_cents' => $priceCents,
                'eighth_applied' => $eighthApplied,
                'available' => $cg <= $remaining,
            ];
        }

        return $presets;
    }

    /**
     * The [price, eighth-applied] a single preset line would cost — the SAME path the basket prices with, so
     * the 3.5 g button shows exactly the eighth price the line will be charged (prompt 83/133).
     *
     * @return array{0: ?int, 1: bool}
     */
    private function presetPrice(?PriceResult $price, int $cg): array
    {
        if ($price === null) {
            return [null, false];
        }

        $result = (new ResolvePrice)->applyEighthBreaks([[
            'grams_cg' => $cg,
            'rate_cents' => $price->effectiveRatePerGramCents(),
            'per_gram_total' => $price->lineFor($cg)['total_cents'],
            'eighth_price' => $price->effectiveEighthPriceCents(),
        ]]);

        return [$result[0]['total_cents'], $result[0]['eighth_applied']];
    }

    /**
     * "Their usual" (prompt 133): the member's most recent DISTINCT genetics, filtered to what is sellable at
     * THIS sede right now (the SAME $sellableRows the grid uses, prompt 95 — so the two can never drift), most
     * recent first, up to three. ONE query on identification (the recent ids), then an in-memory intersect —
     * never a query per suggestion.
     *
     * @param  list<array<string, mixed>>  $sellableRows
     * @return list<array<string, mixed>>
     */
    private function usualGenetics(?Member $member, array $sellableRows): array
    {
        if ($member === null || $sellableRows === []) {
            return [];
        }

        $byId = collect($sellableRows)->keyBy('id');

        return DispensationLine::query()->withoutGlobalScopes()
            ->join('dispensations', 'dispensation_lines.dispensation_id', '=', 'dispensations.id')
            ->where('dispensations.member_id', $member->id)
            ->where('dispensations.status', DispensationStatus::COMPLETED->value)
            ->orderByDesc('dispensations.dispensed_at')
            ->pluck('dispensation_lines.genetic_id')
            ->unique()
            ->filter(fn ($id): bool => $byId->has($id))
            ->take(3)
            ->map(fn ($id) => $byId->get($id))
            ->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function geneticRows(?Location $location, ?Member $member): array
    {
        if ($location === null) {
            return [];
        }

        $resolver = new ResolvePrice;

        // The SAME "sellable at this sede" definition the member menu uses (prompt 95) — one scope, so the
        // counter and the member app can never disagree on what is available.
        /** @var Collection<int, Genetic> $genetics */
        $genetics = Genetic::query()
            ->sellableAt($location->id)
            // The sede's prices ride along so the low-stock verdict reads its threshold without a query per card (273).
            ->with(['category', 'prices' => fn ($query) => $query->withoutGlobalScopes()->where('location_id', $location->id)])
            ->orderBy('name')
            ->get();

        // Prompt 216 — trailing consumption and first-sale dates for the WHOLE grid in two grouped queries,
        // not one pair per card. This pane re-renders on every basket change, every weight keystroke and
        // every source switch, so a per-card query here would be prompt 79's landing-page N+1 again on the
        // screen that renders most.
        $geneticIds = $genetics->pluck('id')->all();
        $trailingCg = StockCover::trailingCgFor($geneticIds, $location->id);
        $firstDispensed = StockCover::firstDispensedAtFor($geneticIds, $location->id);
        // Prompt 273 — the stock for the whole grid in ONE grouped query too; it was two or three per card (remaining,
        // and a FEFO lookup just to answer "has a lote"). 11 varieties cost 119 queries per render, on every key press.
        $stock = StockCover::stockFor($genetics->values()->all(), $location->id);
        // …and each card's price/photo batch (278) in two queries, not one FEFO lookup per card.
        $resolver->preloadDisplayBatches($genetics, $location);

        $rows = [];

        foreach ($genetics as $genetic) {
            try {
                $price = $resolver->forGenetic($genetic, $location, $member);
            } catch (RuntimeException) {
                continue;
            }

            $isUnit = $genetic->isUnitType();
            $remainingUnits = $isUnit ? $stock[$genetic->id]['units'] : null;
            $remainingCg = $isUnit ? ($remainingUnits ?? 0) * (int) $genetic->grams_per_unit_cg : $stock[$genetic->id]['cg'];

            $rows[] = [
                'id' => $genetic->id,
                'name' => $genetic->name,
                'product_type' => $genetic->product_type->value,
                'product_type_label' => $genetic->product_type->label(),
                'is_unit' => $isUnit,
                'strain_type' => $genetic->strain_type?->value,
                'strain_type_label' => $genetic->strain_type?->label(),
                'grams_per_unit_cg' => (int) $genetic->grams_per_unit_cg,
                'thc_bp' => (int) $genetic->thc_bp,
                'cbd_bp' => (int) $genetic->cbd_bp,
                // Prompt 326 — an edible's strength is its THC per unit ("10 mg THC"), not a percentage.
                'thc_mg' => $genetic->product_type === ProductType::EDIBLE ? $genetic->thc_mg_per_unit : null,
                // Pre-labelled like product_type_label / strain_type_label — never the raw enum value, which
                // the blade would have printed as INDOOR/OUTDOOR in both languages (prompt 94).
                'cultivation' => $genetic->cultivation_type?->label(),
                'category_id' => $genetic->category_id,
                'category_name' => $genetic->category?->name,
                'rate_cents' => $price->ratePerGramCents,
                'price_label' => $price->label(),
                'remaining_cg' => $remainingCg,
                'remaining_units' => $remainingUnits,
                // The one resolver, handed the bulk figures — never a second calculation on the screen.
                'cover' => $cover = StockCover::verdictWith(
                    $genetic, $location->id, $remainingCg,
                    $trailingCg[$genetic->id] ?? 0,
                    $firstDispensed[$genetic->id] ?? null,
                ),
                'low_stock' => $cover['low'],
                // Staff screens may carry quantities; the member menu may not (185). "Runs out in about two
                // days at the current rate" is information — the word "low" is not.
                'cover_label' => StockCover::label($cover['days']),
                // A dispensable lote exists exactly when there is dispensable stock — the rule SelectBatch::fefo serves by.
                'has_batch' => ($isUnit ? (int) $remainingUnits : $remainingCg) > 0,
                // Prompt 271 — the first photo from "Añadir variedad", which nothing used to show.
                // The next batch's photo, else the strain's (278 — before, only the strain's, which nothing else showed).
                'image_url' => $resolver->photoUrl($genetic, $location),
            ];
        }

        return $rows;
    }

    /**
     * Distinct product types among the sellable genetics, for the grid filter chips.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{value: string, label: string}>
     */
    private function deriveProductTypes(array $rows): array
    {
        $seen = [];

        foreach ($rows as $row) {
            $seen[(string) $row['product_type']] = (string) $row['product_type_label'];
        }

        $types = [];

        foreach ($seen as $value => $label) {
            $types[] = ['value' => (string) $value, 'label' => $label];
        }

        return $types;
    }

    /**
     * Distinct strain varieties among the sellable genetics, for the grid filter chips (prompt 66).
     * Genetics with no strain type are simply absent from this list (they still show under "All").
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{value: string, label: string}>
     */
    private function deriveStrainTypes(array $rows): array
    {
        $seen = [];

        foreach ($rows as $row) {
            $value = $row['strain_type'] ?? null;
            if ($value !== null) {
                $seen[(string) $value] = (string) $row['strain_type_label'];
            }
        }

        $types = [];

        foreach ($seen as $value => $label) {
            $types[] = ['value' => (string) $value, 'label' => $label];
        }

        return $types;
    }

    /**
     * Distinct categories among the sellable genetics, for the grid filter chips.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{id: string, name: string}>
     */
    private function deriveCategories(array $rows): array
    {
        $seen = [];

        foreach ($rows as $row) {
            if ($row['category_id'] !== null && ! isset($seen[(string) $row['category_id']])) {
                $seen[(string) $row['category_id']] = (string) ($row['category_name'] ?? '—');
            }
        }

        $categories = [];

        foreach ($seen as $id => $name) {
            $categories[] = ['id' => (string) $id, 'name' => $name];
        }

        return $categories;
    }

    private function remainingCg(Genetic $genetic, Location $location): int
    {
        return (int) Batch::query()->withoutGlobalScopes()
            ->where('genetic_id', $genetic->id)
            ->where('location_id', $location->id)
            ->dispensable($location->id)
            ->sum('remaining_cg');
    }

    /** Whole units in stock for a UNIT genetic (open, in-stock, non-expired batches at this sede). */
    private function remainingUnits(Genetic $genetic, Location $location): int
    {
        return (int) Batch::query()->withoutGlobalScopes()
            ->where('genetic_id', $genetic->id)
            ->where('location_id', $location->id)
            ->dispensable($location->id)
            ->sum('remaining_units');
    }

    /**
     * The dispensable batches for the active genetic (FEFO first) — the operator may
     * override the default to another dispensable batch of the same genetic.
     *
     * @return Collection<int, Batch>
     */
    private function activeGeneticBatches(?Location $location): Collection
    {
        if ($this->activeGeneticId === null || $location === null) {
            /** @var Collection<int, Batch> $empty */
            $empty = new Collection;

            return $empty;
        }

        return Batch::query()->withoutGlobalScopes()
            ->where('genetic_id', $this->activeGeneticId)
            ->where('location_id', $location->id)
            ->dispensable($location->id)
            ->orderBy('acquired_or_harvested_on')
            ->orderBy('id')
            ->get();
    }

    private function activeGeneticRateCents(?Location $location, ?Member $member): ?int
    {
        if ($this->activeGeneticId === null || $location === null) {
            return null;
        }

        $genetic = Genetic::query()->find($this->activeGeneticId);

        if ($genetic === null) {
            return null;
        }

        try {
            return (new ResolvePrice)->forGenetic($genetic, $location, $member)->ratePerGramCents;
        } catch (RuntimeException) {
            return null;
        }
    }

    // --- Eligibility helpers ----------------------------------------------------

    /**
     * Failing rules that HARD-block (BLOCK mode) — no override offered.
     *
     * @return list<Rule>
     */
    private function hardBlockRules(EligibilityVerdict $verdict): array
    {
        return array_values(array_filter(
            $verdict->rules,
            fn (array $r): bool => ! $r['satisfied'] && $r['mode'] === 'BLOCK',
        ));
    }

    /**
     * Failing rules in OVERRIDE mode — a permissioned, reasoned override may proceed.
     *
     * @return list<Rule>
     */
    private function overridableRules(EligibilityVerdict $verdict): array
    {
        return array_values(array_filter(
            $verdict->rules,
            fn (array $r): bool => ! $r['satisfied'] && $r['mode'] === 'OVERRIDE',
        ));
    }

    // --- Resolvers (live queries; nothing cached) -------------------------------

    private function resolveLocation(): ?Location
    {
        return $this->locationId !== null ? Location::query()->find($this->locationId) : null;
    }

    /**
     * The socio being served — and nobody while nobody is at the PIN (post-296 audit, 260's rule for reads). The id is
     * kept, so the work survives a lock (198); the member only reaches the browser again once someone identifies.
     */
    private function resolveMember(): ?Member
    {
        return $this->memberId !== null && $this->hasOperator() ? Member::query()->find($this->memberId) : null;
    }

    /** Through the ONE resolver (code-style audit) — this screen and BarPos carried byte-identical copies. */
    private function openTillSession(Location $location): ?TillSession
    {
        return (new SelectTillSession)->handle($location, $this->terminal);
    }

    /** The names of terminals with an OPEN till here — turns a "no till" dead end into a one-click fix. */
    private function openTerminalNames(Location $location): string
    {
        return (new SelectTillSession)->openAt($location)->pluck('terminal')->filter()->unique()->implode(', ');
    }

    private function activeMembership(Member $member, Location $location): ?Membership
    {
        return $member->activeMembershipAt($location);
    }

    private function activeSanction(Member $member): ?MemberSanction
    {
        // The sede's business date (prompt 275) — a sanction ending "yesterday" still binds until the cutoff.
        $location = $this->resolveLocation();
        $today = $location !== null ? BusinessDay::today($location) : now()->toDateString();

        return $member->sanctions()
            ->whereDate('from_date', '<=', $today)
            ->where(fn ($q) => $q->whereNull('until_date')->orWhereDate('until_date', '>=', $today))
            ->latest('from_date')
            ->first();
    }

    private function photoUrl(Member $member): ?string
    {
        // Encrypted photo → authorised, access-logged endpoint only (prompt 113). Null → initials fallback.
        $actor = Auth::user();

        return $actor instanceof User ? VaultUrl::photo($member, $actor, CounterOperator::id()) : null; // op: prompt 261
    }

    private function isCheckedIn(Member $member, Location $location): bool
    {
        return CheckIn::query()->withoutGlobalScopes()
            ->where('member_id', $member->id)
            ->where('location_id', $location->id)
            ->whereNull('checked_out_at')
            ->exists();
    }

    private function signatureRequired(): bool
    {
        // Per-location (prompt 44): the LocationForm's "Firma en dispensación" toggle now genuinely
        // drives this — Settings::get resolves the ACTIVE location first (location → org → default).
        return (bool) Settings::get('signature_on_dispensation', false);
    }

    private function checkedInRequired(): bool
    {
        // Per-location (prompt 44): the LocationForm's "Restringir TPV a socios con check-in" toggle. Prompt 337 — never
        // where the sede does not record entries (Recepción off), whatever is stored: nobody could be served.
        return CounterScreens::receptionEnabled($this->locationId) && (bool) Settings::get('restrict_pos_to_checked_in', false);
    }

    /**
     * The check-in gate, AT COMMIT (prompt 258A). `holdMember()` refuses a not-checked-in member when the sede
     * requires it, but `$memberId` is a public property: set directly, it skipped that gate and the commit
     * dispensed anyway. So both commit paths ask again — with the toggle OFF this is always true.
     */
    private function checkInSatisfied(Member $member, Location $location): bool
    {
        if ($this->checkedInRequired() && ! $this->isCheckedIn($member, $location)) {
            $this->flash(__('El socio no ha registrado su entrada. Regístrala primero en recepción.'), 'error');

            return false;
        }

        return true;
    }

    // --- Small helpers ----------------------------------------------------------

    private function holdMember(string $memberId, bool $scanned): void
    {
        $member = Member::query()->find($memberId);
        $location = $this->resolveLocation();

        if ($member === null) {
            $this->flash(__('Socio no encontrado.'), 'error');

            return;
        }

        // When the sede requires it, only a socio who is checked in may be dispensed to.
        if ($this->checkedInRequired() && $location !== null && ! $this->isCheckedIn($member, $location)) {
            $this->flash(__('El socio no ha registrado su entrada. Regístrala primero en recepción.'), 'error');

            return;
        }

        // Prompt 263 — switching to ANOTHER member with unpaid lines asks first, exactly like "Cerrar". It used to
        // reset the flower basket and KEEP the bar basket, so one member's drinks moved onto the next member's visit.
        if ($this->memberId !== null && $this->memberId !== $member->id && $this->hasUnpaidLines()) {
            $this->confirmDiscard = true;
            $this->clearLookup();

            return;
        }

        $this->memberId = $member->id;
        $this->scanned = $scanned;
        $this->clearLookup();

        // A new socio always starts a fresh basket → a fresh idempotency key (both baskets: prompt 263).
        $this->reset([
            'basket', 'activeGeneticId', 'activeBatchId', 'weightInput', 'calculatorMode', 'unitQty',
            'cashTendered', 'walletInput', 'requireOverride', 'limitBreach', 'overrideReason',
            'signaturePath', 'lastDispensationId', 'lastOrderId', 'voidReason', 'flashMessage', 'barBasket', 'confirmDiscard',
        ]);
        $this->idempotencyKey = (string) Str::ulid();
    }

    /** Clear the basket after a successful commit and mint the next idempotency key. */
    private function resetBasketState(): void
    {
        $this->reset([
            'basket', 'activeGeneticId', 'activeBatchId', 'weightInput', 'calculatorMode', 'unitQty',
            'cashTendered', 'walletInput', 'requireOverride', 'limitBreach', 'overrideReason',
            'priceOverrideEuros', 'priceOverrideReason', 'signaturePath', 'onTab', 'debtCollectInput',
        ]);
        $this->idempotencyKey = (string) Str::ulid();
    }

    private function flash(string $message, string $type): void
    {
        $this->flashSeq++;
        // Any message other than a settled one means the previous outcome is no longer what is on screen
        // (prompt 202). `flashSettled()` re-sets it immediately after; nothing else may.
        $this->settled = [];

        $this->flashMessage = $message;
        $this->flashType = $type;
    }

    /**
     * Prompt 211 — the socio held at the POS. 203's concern defaults to Socios' `\$feeMemberId`; this screen's subject is the
     * member the operator pulled in to dispense to.
     */
    protected function membershipSubjectId(): ?string
    {
        return $this->memberId;
    }

    /**
     * Prompt 229 — and the same answer for the fee concern's READ path. 219's waiver reasons are computed at
     * render, before any action has pointed `$feeMemberId` anywhere, so without this the socio held at the POS has no
     * structured reasons on a fresh open and nothing is preselected.
     */
    protected function feeSubjectId(): ?string
    {
        return $this->memberId;
    }

    /**
     * Forgo this member's outstanding fee (prompt 219) — the shared concern does the work.
     *
     * A thin resolve-and-call, like `collectFee`: the rule, the reason and the audit live in
     * {@see CollectsMembershipFees::waiveFeeThrough}, so all three hosts
     * behave identically and there is one place to read.
     */
    public function waiveFee(): void
    {
        if (! $this->requireOperator()) {
            return;
        }

        $location = $this->resolveLocation();
        $user = $this->counterActor();

        if ($location === null || $user === null) {
            $this->flash(__('Sin sede activa.'), 'error');

            return;
        }

        // This screen holds its socio in `$memberId`; the shared concern keys off `$feeMemberId`. Bridged in
        // the concern (prompt 219), the same way prompt 211 bridged it for `OpensMemberships`.
        $member = $this->memberId !== null ? Member::query()->find($this->memberId) : null;

        if ($member === null) {
            $this->flash(__('Selecciona un socio.'), 'error');

            return;
        }

        $result = $this->waiveInlineFeeFor($member, $location, $user);
        $this->flashResult($result);
    }
}
