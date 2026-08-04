<?php

namespace App\Livewire\Counter;

use App\Actions\Attendance\ResolveMemberEligibility;
use App\Actions\Counter\CommitCombinedSettle;
use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Dispensing\ResolveMemberLimits;
use App\Actions\Dispensing\VoidDispensation;
use App\Actions\Members\ResolveMemberByToken;
use App\Actions\Pricing\ResolveArticleDiscount;
use App\Actions\Pricing\ResolvePrice;
use App\Actions\ResolveLocale;
use App\Actions\Stock\SelectBatch;
use App\Enums\DispensationStatus;
use App\Enums\MembershipStatus;
use App\Enums\TillSessionStatus;
use App\Exceptions\DebtLimitExceededException;
use App\Exceptions\DispensationBlockedException;
use App\Exceptions\LimitExceededException;
use App\Exceptions\ScanRateLimitedException;
use App\Exceptions\TillClosedException;
use App\Livewire\Counter\Concerns\CollectsMembershipFees;
use App\Livewire\Counter\Concerns\HandlesTender;
use App\Livewire\Counter\Concerns\IdentifiesOperator;
use App\Livewire\Counter\Concerns\ResolvesCounterLocation;
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
use App\Support\CounterOperator;
use App\Support\DocumentVault;
use App\Support\EligibilityVerdict;
use App\Support\LimitSnapshot;
use App\Support\Money;
use App\Support\PriceResult;
use App\Support\Settings;
use App\Support\TerminalName;
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
 * @phpstan-type Line array{genetic_id: string, batch_id: string, grams_cg: int, units: ?int}
 * @phpstan-type Rule array{rule: string, satisfied: bool, mode: string, message: string}
 */
#[Layout('components.layouts.counter')]
class DispensaryPos extends Component
{
    use CollectsMembershipFees, HandlesTender, IdentifiesOperator, ResolvesCounterLocation;

    // --- Identity ---------------------------------------------------------------

    /** Bound to the scan input — a keyboard-wedge scanner types the token then hits Enter. */
    public string $scan = '';

    /** Live org-wide fallback search to identify a socio (name or member number). */
    public string $search = '';

    /** Live filter over the genetics grid (name). */
    public string $geneticSearch = '';

    /** Category filter over the genetics grid (null = all). */
    public ?string $categoryId = null;

    /** Product-type filter over the genetics grid (FLOWER|CONCENTRATE|PREROLL|EDIBLE, null = all). */
    public ?string $productType = null;

    /** Strain-variety filter over the genetics grid (SATIVA|INDICA|HYBRID, null = all — prompt 66). */
    public ?string $strainType = null;

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
     * The OPTIONAL bar/merch side of the same visit (prompt 118): a list of {article_id, qty}. When present at
     * settle, the visit is committed through CommitCombinedSettle — one payment, but a Dispensation AND an
     * Order on their separate ledgers. Empty (the common case) leaves the plain dispensation commit untouched.
     *
     * @var list<array{article_id: string, qty: int}>
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

    public function mount(): void
    {
        abort_unless($this->userCan('pos.use'), 403);

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
    }

    // --- Identify ---------------------------------------------------------------

    public function submitScan(): void
    {
        $token = trim($this->scan);
        $this->scan = '';

        if ($token === '') {
            return;
        }

        try {
            $member = (new ResolveMemberByToken)->handle($token, (string) (Auth::id() ?? request()->ip()));
        } catch (ScanRateLimitedException) {
            $this->flash(__('Demasiados intentos de escaneo. Espera unos segundos.'), 'error');

            return;
        }

        if ($member === null) {
            // A name (or member number) typed into the scan field falls through to the SAME search
            // (prompt 91), so the lookup never depends on the operator noticing which of two adjacent boxes
            // has the cursor. If it really was a card, the search simply returns nothing and they retype.
            $this->search = $token;
            $this->flash(__('Tarjeta no reconocida. Buscando por nombre o nº de socio…'), 'warning');

            return;
        }

        $this->holdMember($member->id, scanned: true);
    }

    /** A camera-decoded QR token routes through the SAME lookup as the wedge scanner (prompt 35). */
    public function submitCameraScan(string $token): void
    {
        $this->scan = $token;
        $this->submitScan();
    }

    public function selectMember(string $memberId): void
    {
        $this->holdMember($memberId, scanned: false);
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

        $user = $this->currentUser();
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
        $this->flash($result['message'], $result['type']);
    }

    public function clearMember(): void
    {
        $this->reset([
            'memberId', 'scanned', 'search', 'basket', 'activeGeneticId', 'activeBatchId',
            'weightInput', 'calculatorMode', 'unitQty', 'cashTendered', 'walletInput', 'requireOverride',
            'limitBreach', 'overrideReason', 'priceOverrideEuros', 'priceOverrideReason', 'signaturePath',
            'lastDispensationId', 'lastOrderId', 'barBasket', 'voidReason', 'flashMessage',
        ]);

        // A new socio always starts a fresh basket → a fresh idempotency key.
        $this->idempotencyKey = (string) Str::ulid();
    }

    // --- Genetic → weight → basket ----------------------------------------------

    public function chooseGenetic(string $geneticId): void
    {
        if ($this->resolveMember() === null) {
            $this->flash(__('Identifica a un socio antes de añadir producto.'), 'error');

            return;
        }

        $this->activeGeneticId = $geneticId;
        $this->weightInput = '';
        $this->calculatorMode = false;
        $this->unitQty = 1;

        // Default the batch to FEFO (oldest open, non-expired, in stock); overridable below.
        $location = $this->resolveLocation();
        $genetic = Genetic::query()->find($geneticId);
        $this->activeBatchId = ($location !== null && $genetic !== null)
            ? (new SelectBatch)->fefo($genetic, $location)?->id
            : null;
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

    public function filterProductType(?string $productType): void
    {
        $this->productType = $productType;
    }

    public function filterStrainType(?string $strainType): void
    {
        $this->strainType = $strainType;
    }

    public function toggleCalculator(): void
    {
        $this->calculatorMode = ! $this->calculatorMode;
        $this->weightInput = '';
    }

    public function filterCategory(?string $categoryId): void
    {
        $this->categoryId = $categoryId;
    }

    /** Numeric pad: append a digit / decimal, backspace or clear. */
    public function pad(string $key): void
    {
        if ($key === 'clear') {
            $this->weightInput = '';

            return;
        }

        if ($key === 'back') {
            $this->weightInput = mb_substr($this->weightInput, 0, -1);

            return;
        }

        if ($key === ',') {
            if (! str_contains($this->weightInput, ',')) {
                $this->weightInput = ($this->weightInput === '' ? '0' : $this->weightInput).',';
            }

            return;
        }

        $this->weightInput .= $key;
    }

    public function addLine(): void
    {
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

        // Batch: the chosen one (default FEFO), refused unless dispensable (open, in stock, not expired).
        $batch = $this->activeBatchId !== null
            ? Batch::query()->withoutGlobalScopes()->find($this->activeBatchId)
            : null;

        if ($batch === null || ! (new SelectBatch)->isDispensable($batch)) {
            $this->flash(__('No hay lote disponible para dispensar (agotado o caducado).'), 'error');

            return;
        }

        // UNIT genetic → the stepper drives whole units; grams_cg is computed. WEIGHT → grams pad.
        if ($genetic->isUnitType()) {
            $units = max(1, $this->unitQty);
            $line = [
                'genetic_id' => $genetic->id,
                'batch_id' => $batch->id,
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
                'batch_id' => $batch->id,
                'grams_cg' => $gramsCg,
                'units' => null,
            ];
        }

        $this->basket[] = $line;

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
        $this->signaturePath = $path;
        $this->flash(__('Firma capturada.'), 'success');
    }

    public function clearSignature(): void
    {
        $this->signaturePath = null;
    }

    // --- Commit -----------------------------------------------------------------

    public function commit(): void
    {
        $this->attemptCommit(override: false);
    }

    public function commitWithOverride(): void
    {
        $this->attemptCommit(override: true);
    }

    private function attemptCommit(bool $override): void
    {
        $member = $this->resolveMember();
        $location = $this->resolveLocation();

        // The "no cannabis line without a member" rule — enforced here, asserted in the tests.
        if ($member === null || $location === null) {
            $this->flash(__('Identifica a un socio antes de registrar una dispensación.'), 'error');

            return;
        }

        // Attribution: a PIN-identified operator is required — never the device session user.
        if (! $this->requireOperator()) {
            return;
        }

        // Fail closed: a commit made offline cannot be trusted (limits/stock/balances are live-query).
        if ($this->offline) {
            $this->flash(__('Sin conexión: no se puede registrar. La cesta se conserva hasta reconectar.'), 'error');

            return;
        }

        if ($this->basket === []) {
            $this->flash(__('La cesta está vacía.'), 'error');

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
            $user = $this->currentUser();

            if ($user === null || ! $user->can('dispensation.price.override')) {
                $this->flash(__('No tienes permiso para ajustar el precio.'), 'error');

                return;
            }

            if (trim($this->priceOverrideReason) === '') {
                $this->flash(__('Indica el motivo del ajuste de precio (queda registrado).'), 'error');

                return;
            }

            $entered = (int) round_half_up(((float) str_replace(',', '.', trim($this->priceOverrideEuros))) * 100);
            $priceOverrideCents = max(0, min($entered, $resolvedTotal)); // reduce only: 0 (free) .. resolved
            $total = $priceOverrideCents;
        }

        [$cashCents, $walletCents] = $this->tenderSplit($total);

        // The split is DERIVED (cash = total − wallet) so it always reconciles — the fix for prompt 74: the
        // Cash field is what the member HANDED, not the charge. The only tender error is an UNDER-tender
        // (handed less cash than owed); over-tender is fine and produces change. Tender is measured against
        // $total, which is already the OVERRIDDEN total when a price override is applied (prompt 64).
        if ($this->isUnderTendered($cashCents)) {
            $this->flash(__('El efectivo entregado no cubre el total.'), 'error');

            return;
        }

        $options = [
            'operator_id' => CounterOperator::id() ?? $this->currentUser()?->id,
            'till_session_id' => $till->id,
            'cash_cents' => $cashCents,
            'wallet_cents' => $walletCents,
            'idempotency_key' => $this->idempotencyKey,
        ];

        if ($this->signaturePath !== null) {
            $options['signature_path'] = $this->signaturePath;
        }

        if ($priceOverrideCents !== null) {
            $options['price_override_cents'] = $priceOverrideCents;
            $options['price_override_reason'] = trim($this->priceOverrideReason);
            $options['price_override_by'] = $this->currentUser();
        }

        if ($override) {
            $user = $this->currentUser();

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
            'batch_id' => (string) $line['batch_id'],
            'grams_cg' => (int) $line['grams_cg'],
            'units' => $line['units'] !== null ? (int) $line['units'] : null,
        ], $this->basket);

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
        } catch (RuntimeException) {
            $this->flash(__('No se pudo registrar la dispensación. Revisa la cesta y el stock.'), 'error');

            return;
        }

        $this->lastDispensationId = $dispensation->id;
        $this->resetBasketState();
        $this->flash(__('Dispensación registrada.'), 'success');
    }

    // --- Combined settle: same visit, cannabis + bar, two records (prompt 118) --------

    /** Add a bar/merch article to the visit's bar side. Articles only — a genetic can never appear here. */
    public function addBarItem(string $articleId): void
    {
        $location = $this->resolveLocation();
        if ($location === null) {
            return;
        }

        $article = Article::query()->where('location_id', $location->id)->find($articleId);
        if ($article === null || ! $article->active) {
            return;
        }

        foreach ($this->barBasket as $i => $line) {
            if ($line['article_id'] === $articleId) {
                $this->barBasket[$i]['qty']++;

                return;
            }
        }
        $this->barBasket[] = ['article_id' => $articleId, 'qty' => 1];
    }

    public function removeBarItem(int $index): void
    {
        unset($this->barBasket[$index]);
        $this->barBasket = array_values($this->barBasket);
    }

    /**
     * Settle the whole visit in one payment: a Dispensation AND a bar Order, atomically, on separate ledgers
     * (prompt 118). Reuses the SAME dispensation guards as a plain commit (eligibility, signature, open till),
     * then hands both baskets to CommitCombinedSettle. The quick combined path stays for the clean case: a
     * dispensation that needs a limit/price override uses the ordinary dispensation flow.
     */
    public function settleWithBar(): void
    {
        $member = $this->resolveMember();
        $location = $this->resolveLocation();

        if ($member === null || $location === null) {
            $this->flash(__('Identifica a un socio antes de registrar una dispensación.'), 'error');

            return;
        }
        if (! $this->requireOperator()) {
            return;
        }
        if ($this->offline) {
            $this->flash(__('Sin conexión: no se puede registrar. La cesta se conserva hasta reconectar.'), 'error');

            return;
        }
        if ($this->basket === [] || $this->barBasket === []) {
            $this->flash(__('Una liquidación combinada necesita cesta de dispensario y de barra.'), 'error');

            return;
        }

        $verdict = (new ResolveMemberEligibility)->handle($member, $location, 'counter');
        if ($this->hardBlockRules($verdict) !== []) {
            $this->flash(__('Dispensación bloqueada: :reasons', ['reasons' => implode(' · ', $verdict->blockingMessages())]), 'error');

            return;
        }
        // An override-needed dispensation is not quick-settled with the bar — use the ordinary flow for it.
        if ($this->overridableRules($verdict) !== [] || $this->limitBreach) {
            $this->flash(__('Esta dispensación requiere autorización: liquídala por separado.'), 'warning');

            return;
        }
        if ($this->signatureRequired() && $this->signaturePath === null) {
            $this->flash(__('Falta la firma del socio.'), 'warning');

            return;
        }

        $till = $this->openTillSession($location);
        if ($till === null) {
            $this->flash(__('No hay caja abierta en este terminal.'), 'error');

            return;
        }

        $dispTotal = $this->basketTotalCents($member, $location);
        $barTotal = $this->barBasketTotalCents($member, $location);
        [$cashApplied, $walletApplied] = $this->tenderSplit($dispTotal + $barTotal);

        if ($this->isUnderTendered($cashApplied)) {
            $this->flash(__('El efectivo entregado no cubre el total.'), 'error');

            return;
        }

        // Allocate the one payment across the two records: wallet to the dispensation first, then the bar; the
        // cash remainder fills each. The split reconciles by construction (Σwallet + Σcash = combined total).
        $dispWallet = min($walletApplied, $dispTotal);
        $barWallet = $walletApplied - $dispWallet;

        $dispLines = array_map(fn (array $l): array => [
            'genetic_id' => (string) $l['genetic_id'],
            'batch_id' => (string) $l['batch_id'],
            'grams_cg' => (int) $l['grams_cg'],
            'units' => $l['units'] !== null ? (int) $l['units'] : null,
        ], $this->basket);
        $orderLines = array_map(fn (array $l): array => ['article_id' => (string) $l['article_id'], 'qty' => (int) $l['qty']], $this->barBasket);

        $operatorId = CounterOperator::id() ?? $this->currentUser()?->id;
        $dispOptions = ['cash_cents' => $dispTotal - $dispWallet, 'wallet_cents' => $dispWallet, 'idempotency_key' => $this->idempotencyKey];
        if ($this->signaturePath !== null) {
            $dispOptions['signature_path'] = $this->signaturePath;
        }

        try {
            $result = (new CommitCombinedSettle)->handle($member, $location, $dispLines, $orderLines, [
                'till_session_id' => $till->id,
                'operator_id' => $operatorId,
                'dispensation' => $dispOptions,
                'order' => [
                    'cash_cents' => $barTotal - $barWallet,
                    'wallet_cents' => $barWallet,
                    'idempotency_key' => $this->idempotencyKey !== null ? $this->idempotencyKey.'-bar' : null,
                ],
            ]);
        } catch (DebtLimitExceededException) {
            $this->flash(__('El pago combinado con monedero superaría el saldo disponible del socio.'), 'error');

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
            $article = Article::query()->where('location_id', $location->id)->find($line['article_id']);
            if ($article === null) {
                continue;
            }
            $gross = $article->price_cents->cents * max(1, (int) $line['qty']);
            $total += $gross - $discounter->discountCents($gross, $bp);
        }

        return $total;
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

        $user = $this->currentUser();

        if ($user === null || ! $user->can('dispensation.void')) {
            $this->flash(__('No tienes permiso para anular una dispensación.'), 'error');

            return;
        }

        $reason = trim($this->voidReason);

        if ($reason === '') {
            $this->flash(__('Indica el motivo de la anulación (queda registrado).'), 'error');

            return;
        }

        $dispensation = Dispensation::query()->withoutGlobalScopes()->find($this->lastDispensationId);

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
        if ($this->lastDispensationId === null) {
            return;
        }

        $dispensation = Dispensation::query()->withoutGlobalScopes()->with(['member', 'lines'])->find($this->lastDispensationId);
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
            $limits = (new ResolveMemberLimits)->handle($member, $location);
            $membership = $this->activeMembership($member, $location);
            $walletCents = Wallet::balance($member->id, $location->id);
        }

        $basketLines = $this->basketView($member, $location);
        $total = (int) array_sum(array_map(fn (array $l): int => (int) $l['total_cents'], $basketLines));
        [$cashPreview, $walletPreview] = $this->tenderSplit($total);

        $allGenetics = $this->geneticRows($location, $member);
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
            'photoUrl' => $member !== null ? $this->photoUrl($member) : null,
            'searchResults' => $this->searchResults($location),
            'genetics' => $this->filterGenetics($allGenetics),
            'categories' => $this->deriveCategories($allGenetics),
            'productTypes' => $this->deriveProductTypes($allGenetics),
            'strainTypes' => $this->deriveStrainTypes($allGenetics),
            'activeEntryGramsCg' => $this->activeEntryGramsCg(),
            'basketLines' => $basketLines,
            'basketTotalCents' => $total,
            'cashPreviewCents' => $cashPreview,
            'walletPreviewCents' => $walletPreview,
            'changeDueCents' => $this->changeDueCents($cashPreview),
            'activeGenetic' => $activeGeneticModel,
            'weightPresets' => $this->weightPresets($activeGeneticModel, $location, $member, $limits),
            'usualGenetics' => $this->usualGenetics($member, $allGenetics),
            'activeGeneticBatches' => $this->activeGeneticBatches($location),
            'activeGeneticPriceCents' => $this->activeGeneticRateCents($location, $member),
            'openTill' => $openTill,
            'requireSignature' => $this->signatureRequired(),
            'requireCheckedIn' => $this->checkedInRequired(),
            'cameraScanEnabled' => (bool) Settings::get('camera_scan_enabled', false),
            'hardBlockRules' => $verdict !== null ? $this->hardBlockRules($verdict) : [],
            'overridableRules' => $verdict !== null ? $this->overridableRules($verdict) : [],
            'canOverride' => $this->userCan('limits.override'),
            'canVoid' => $this->userCan('dispensation.void'),
            // Inline fee (prompt 127): the collect action follows the unpaid-fee verdict onto the POS card.
            'canCollectFee' => $this->userCan('membership.fee.collect'),
            'feeOwedCents' => $membership !== null ? $this->owedCents($membership) : 0,
            'openTillPresent' => $openTill !== null,
            // Bar side of the same visit (prompt 118) — only where the sede runs a bar. barArticles feeds the
            // quick-add; barLines + barTotalCents render the in-progress bar basket.
            'barEnabled' => $location !== null && (bool) Settings::get('bar_enabled', true, $location->id),
            'barArticles' => $this->barArticleRows($location),
            'barLines' => $this->barBasketView($location),
            'barTotalCents' => $this->barBasketTotalCents($member, $location),
        ]);
    }

    /**
     * The articles this sede can add to a visit's bar side — active, in stock — for the quick-add.
     *
     * @return list<array{id: string, name: string, price_cents: int}>
     */
    private function barArticleRows(?Location $location): array
    {
        if ($location === null) {
            return [];
        }

        return Article::query()->where('location_id', $location->id)->where('active', true)->where('stock', '>', 0)
            ->orderBy('name')->get()
            ->map(fn (Article $a): array => ['id' => $a->id, 'name' => $a->name, 'price_cents' => $a->price_cents->cents])
            ->all();
    }

    /**
     * The in-progress bar basket resolved for display (name + qty + line total, live-priced).
     *
     * @return list<array{index: int, name: string, qty: int, line_total_cents: int}>
     */
    private function barBasketView(?Location $location): array
    {
        if ($location === null) {
            return [];
        }

        $rows = [];
        foreach ($this->barBasket as $index => $line) {
            $article = Article::query()->where('location_id', $location->id)->find($line['article_id']);
            if ($article === null) {
                continue;
            }
            $qty = max(1, (int) $line['qty']);
            $rows[] = ['index' => $index, 'name' => $article->name, 'qty' => $qty, 'line_total_cents' => $article->price_cents->cents * $qty];
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

        return $this->parseGramsCg($this->weightInput);
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
        if (! $this->calculatorMode) {
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

    /** The live basket total the shared HandlesTender model splits/tenders against (pre price-override). */
    protected function tenderableTotalCents(): int
    {
        $location = $this->resolveLocation();

        return $location === null ? 0 : $this->basketTotalCents($this->resolveMember(), $location);
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

            try {
                $price = $resolver->forGenetic($genetic, $location, $member);
                $priced = $units !== null
                    ? $price->lineForUnits((int) $units)
                    : $price->lineFor((int) $line['grams_cg']);
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
            ];
            $eighthInput[] = $units !== null
                ? ['grams_cg' => (int) $line['grams_cg'], 'rate_cents' => 0, 'per_gram_total' => $priced['total_cents'], 'eighth_price' => null]
                : ['grams_cg' => (int) $line['grams_cg'], 'rate_cents' => $price->effectiveRatePerGramCents(), 'per_gram_total' => $priced['total_cents'], 'eighth_price' => $price->effectiveEighthPriceCents()];
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

    private function basketTotalCents(?Member $member, ?Location $location): int
    {
        return array_sum(array_map(
            fn (array $l): int => (int) $l['total_cents'],
            $this->basketView($member, $location),
        ));
    }

    /**
     * The genetics sellable at this sede — active, with an active base price here.
     * Each row carries its live per-gram price and remaining stock.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * Apply a one-tap weight preset (prompt 133). It only FILLS the same weight input a typed amount would, so
     * eligibility, carencia and the daily/monthly limits are enforced identically at addLine — a preset is an
     * input, never a fast path around the checks.
     */
    public function applyWeightPreset(int $gramsCg): void
    {
        $this->calculatorMode = false;
        $this->weightInput = str_replace('.', ',', rtrim(rtrim(number_format($gramsCg / 100, 2, '.', ''), '0'), '.'));
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
        $limits = ($member !== null && $location !== null) ? (new ResolveMemberLimits)->handle($member, $location) : null;

        return $this->weightPresets($genetic, $location, $member, $limits);
    }

    /**
     * The current member's "usual" genetics (component state) — public entry point for the view and tests.
     *
     * @return list<array<string, mixed>>
     */
    public function theirUsual(): array
    {
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
            $grams = (float) str_replace(',', '.', (string) $g);
            if ($grams <= 0) {
                continue;
            }
            $cg = (int) round_half_up($grams * 100);
            [$priceCents, $eighthApplied] = $this->presetPrice($price, $cg);

            $presets[] = [
                'grams_cg' => $cg,
                'label' => rtrim(rtrim(number_format($grams, 2, ',', ''), '0'), ','),
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
            ->with('category')
            ->orderBy('name')
            ->get();

        $rows = [];

        foreach ($genetics as $genetic) {
            try {
                $price = $resolver->forGenetic($genetic, $location, $member);
            } catch (RuntimeException) {
                continue;
            }

            $isUnit = $genetic->isUnitType();
            $remainingUnits = $isUnit ? $this->remainingUnits($genetic, $location) : null;
            $remainingCg = $isUnit ? ($remainingUnits ?? 0) * (int) $genetic->grams_per_unit_cg : $this->remainingCg($genetic, $location);

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
                // Pre-labelled like product_type_label / strain_type_label — never the raw enum value, which
                // the blade would have printed as INDOOR/OUTDOOR in both languages (prompt 94).
                'cultivation' => $genetic->cultivation_type?->label(),
                'category_id' => $genetic->category_id,
                'category_name' => $genetic->category?->name,
                'rate_cents' => $price->ratePerGramCents,
                'price_label' => $price->label(),
                'remaining_cg' => $remainingCg,
                'remaining_units' => $remainingUnits,
                'low_stock' => $genetic->isLowStockAt($remainingCg, $location->id),
                'has_batch' => (new SelectBatch)->fefo($genetic, $location) !== null,
            ];
        }

        return $rows;
    }

    /**
     * Apply the grid's live name search + category filter (in memory — the sellable
     * set was already queried live above).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function filterGenetics(array $rows): array
    {
        $term = mb_strtolower(trim($this->geneticSearch));

        return array_values(array_filter($rows, function (array $row) use ($term): bool {
            if ($this->categoryId !== null && (string) $row['category_id'] !== $this->categoryId) {
                return false;
            }

            if ($this->productType !== null && (string) $row['product_type'] !== $this->productType) {
                return false;
            }

            if ($this->strainType !== null && (string) ($row['strain_type'] ?? '') !== $this->strainType) {
                return false;
            }

            return $term === '' || str_contains(mb_strtolower((string) $row['name']), $term);
        }));
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
            ->dispensable()
            ->sum('remaining_cg');
    }

    /** Whole units in stock for a UNIT genetic (open, in-stock, non-expired batches at this sede). */
    private function remainingUnits(Genetic $genetic, Location $location): int
    {
        return (int) Batch::query()->withoutGlobalScopes()
            ->where('genetic_id', $genetic->id)
            ->where('location_id', $location->id)
            ->dispensable()
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
            ->dispensable()
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

    private function resolveMember(): ?Member
    {
        return $this->memberId !== null ? Member::query()->find($this->memberId) : null;
    }

    private function openTillSession(Location $location): ?TillSession
    {
        $sessions = $this->openSessionsAt($location);

        if ($this->terminal === '') {
            return $sessions->first();
        }

        // Match by normalised KEY, not the raw string, so a spelling variant still resolves (prompt 84).
        $key = TerminalName::key($this->terminal);

        return $sessions->first(fn (TillSession $s): bool => TerminalName::key((string) $s->terminal) === $key);
    }

    /** @return \Illuminate\Support\Collection<int, TillSession> */
    private function openSessionsAt(Location $location): \Illuminate\Support\Collection
    {
        return TillSession::query()->withoutGlobalScopes()
            ->where('location_id', $location->id)
            ->where('status', TillSessionStatus::OPEN->value)
            ->orderBy('opened_at')->get();
    }

    /** The names of terminals with an OPEN till here — turns a "no till" dead end into a one-click fix. */
    private function openTerminalNames(Location $location): string
    {
        return $this->openSessionsAt($location)->pluck('terminal')->filter()->unique()->implode(', ');
    }

    private function activeMembership(Member $member, Location $location): ?Membership
    {
        return $member->memberships()->withoutGlobalScopes()
            ->where('location_id', $location->id)
            ->where('status', MembershipStatus::ACTIVE->value)
            ->latest('id')
            ->first();
    }

    private function activeSanction(Member $member): ?MemberSanction
    {
        $today = now()->toDateString();

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

        return $actor instanceof User ? VaultUrl::photo($member, $actor) : null;
    }

    /**
     * Org-wide socio search (crosses locations by design — Member is org-scoped). When
     * the sede requires check-in first, results are restricted to socios inside now.
     *
     * @return Collection<int, Member>|null
     */
    private function searchResults(?Location $location): ?Collection
    {
        $term = trim($this->search);

        if (mb_strlen($term) < 2) {
            return null;
        }

        $query = Member::query()
            ->where(fn ($q) => $q
                ->where('first_name', 'like', '%'.$term.'%')
                ->orWhere('last_name', 'like', '%'.$term.'%')
                ->orWhere('member_no', 'like', '%'.$term.'%'))
            ->orderBy('last_name')
            ->limit(8);

        if ($this->checkedInRequired() && $location !== null) {
            $query->whereIn('id', $this->checkedInMemberIds($location));
        }

        return $query->get();
    }

    /** @return list<string> */
    private function checkedInMemberIds(Location $location): array
    {
        return CheckIn::query()->withoutGlobalScopes()
            ->where('location_id', $location->id)
            ->whereNull('checked_out_at')
            ->pluck('member_id')
            ->map(fn ($id): string => (string) $id)
            ->all();
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
        // Per-location (prompt 44): the LocationForm's "Restringir TPV a socios con check-in" toggle.
        return (bool) Settings::get('restrict_pos_to_checked_in', false);
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

        $this->memberId = $member->id;
        $this->scanned = $scanned;
        $this->search = '';

        // A new socio always starts a fresh basket → a fresh idempotency key.
        $this->reset([
            'basket', 'activeGeneticId', 'activeBatchId', 'weightInput', 'calculatorMode', 'unitQty',
            'cashTendered', 'walletInput', 'requireOverride', 'limitBreach', 'overrideReason',
            'signaturePath', 'lastDispensationId', 'voidReason', 'flashMessage',
        ]);
        $this->idempotencyKey = (string) Str::ulid();
    }

    /** Clear the basket after a successful commit and mint the next idempotency key. */
    private function resetBasketState(): void
    {
        $this->reset([
            'basket', 'activeGeneticId', 'activeBatchId', 'weightInput', 'calculatorMode', 'unitQty',
            'cashTendered', 'walletInput', 'requireOverride', 'limitBreach', 'overrideReason',
            'priceOverrideEuros', 'priceOverrideReason', 'signaturePath',
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
