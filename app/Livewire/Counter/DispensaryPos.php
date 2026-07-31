<?php

namespace App\Livewire\Counter;

use App\Actions\Attendance\ResolveMemberEligibility;
use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Dispensing\ResolveMemberLimits;
use App\Actions\Dispensing\VoidDispensation;
use App\Actions\Members\ResolveMemberByToken;
use App\Actions\Pricing\ResolvePrice;
use App\Actions\Stock\SelectBatch;
use App\Enums\MembershipStatus;
use App\Enums\TillSessionStatus;
use App\Exceptions\DispensationBlockedException;
use App\Exceptions\LimitExceededException;
use App\Exceptions\ScanRateLimitedException;
use App\Exceptions\TillClosedException;
use App\Livewire\Counter\Concerns\IdentifiesOperator;
use App\Models\Batch;
use App\Models\CheckIn;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberSanction;
use App\Models\Membership;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\EligibilityVerdict;
use App\Support\Money;
use App\Support\Settings;
use App\Support\Wallet;
use App\Support\Weight;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;
use Throwable;

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
    use IdentifiesOperator;

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

    /** The held socio (id only — the model is resolved live, never stored on the component). */
    public ?string $memberId = null;

    /** True when the held member arrived via a scanned card. */
    public bool $scanned = false;

    /** The active location id, resolved in mount(). */
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

    // --- Tender -----------------------------------------------------------------

    public string $cashInput = '';

    public string $walletInput = '';

    // --- Override (permissioned, reasoned) --------------------------------------

    /** A limit breach / OVERRIDE-mode block needs a reasoned, permissioned override to proceed. */
    public bool $requireOverride = false;

    /** The last attempt hit a consumption-limit breach (offer the override). */
    public bool $limitBreach = false;

    public string $overrideReason = '';

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
            $this->flash(__('Tarjeta no reconocida. Inténtalo de nuevo o busca por nombre.'), 'error');

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

    public function clearMember(): void
    {
        $this->reset([
            'memberId', 'scanned', 'search', 'basket', 'activeGeneticId', 'activeBatchId',
            'weightInput', 'calculatorMode', 'unitQty', 'cashInput', 'walletInput', 'requireOverride',
            'limitBreach', 'overrideReason', 'signaturePath', 'lastDispensationId', 'voidReason',
            'flashMessage',
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
            'cashInput', 'walletInput', 'requireOverride', 'limitBreach', 'overrideReason',
            'signaturePath',
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
        Storage::disk('documents')->put($path, $binary);
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
            $this->flash(__('No hay caja abierta en este terminal.'), 'error');

            return;
        }

        $total = $this->basketTotalCents($member, $location);
        [$cashCents, $walletCents] = $this->tenderSplit($total);

        if ($cashCents < 0 || $walletCents < 0 || ($cashCents + $walletCents) !== $total) {
            $this->flash(__('El desglose de pago (efectivo + monedero) no cuadra con el total.'), 'error');

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

    // --- Void -------------------------------------------------------------------

    public function voidLast(): void
    {
        if ($this->lastDispensationId === null) {
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

    // --- View data (assembled here; the view stays declarative) -----------------

    public function render(): View
    {
        $location = $this->resolveLocation();
        $member = $this->resolveMember();

        $verdict = null;
        $limits = null;
        $membership = null;
        $walletCents = 0;

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
            'activeEntryGramsCg' => $this->activeEntryGramsCg(),
            'basketLines' => $basketLines,
            'basketTotalCents' => $total,
            'cashPreviewCents' => $cashPreview,
            'walletPreviewCents' => $walletPreview,
            'activeGenetic' => $this->activeGeneticId !== null ? Genetic::query()->find($this->activeGeneticId) : null,
            'activeGeneticBatches' => $this->activeGeneticBatches($location),
            'activeGeneticPriceCents' => $this->activeGeneticRateCents($location, $member),
            'openTill' => $location !== null ? $this->openTillSession($location) : null,
            'requireSignature' => $this->signatureRequired(),
            'requireCheckedIn' => $this->checkedInRequired(),
            'cameraScanEnabled' => (bool) Settings::get('camera_scan_enabled', false),
            'hardBlockRules' => $verdict !== null ? $this->hardBlockRules($verdict) : [],
            'overridableRules' => $verdict !== null ? $this->overridableRules($verdict) : [],
            'canOverride' => $this->userCan('limits.override'),
            'canVoid' => $this->userCan('dispensation.void'),
        ]);
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

    /** @return array{0: int, 1: int} [cashCents, walletCents] — the two default to a full cash contribution. */
    private function tenderSplit(int $total): array
    {
        $wallet = $this->walletInput === '' ? 0 : ($this->parseCents($this->walletInput) ?? 0);
        $cash = $this->cashInput === '' ? max(0, $total - $wallet) : ($this->parseCents($this->cashInput) ?? 0);

        return [$cash, $wallet];
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
            ];
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
    private function geneticRows(?Location $location, ?Member $member): array
    {
        if ($location === null) {
            return [];
        }

        $resolver = new ResolvePrice;

        /** @var Collection<int, Genetic> $genetics */
        $genetics = Genetic::query()
            ->where('active', true)
            ->whereHas('prices', fn ($q) => $q->withoutGlobalScopes()
                ->where('location_id', $location->id)
                ->whereNull('tier_id')
                ->where('active', true))
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

            $rows[] = [
                'id' => $genetic->id,
                'name' => $genetic->name,
                'product_type' => $genetic->product_type->value,
                'product_type_label' => $genetic->product_type->label(),
                'is_unit' => $isUnit,
                'grams_per_unit_cg' => (int) $genetic->grams_per_unit_cg,
                'thc_bp' => (int) $genetic->thc_bp,
                'cbd_bp' => (int) $genetic->cbd_bp,
                'cultivation' => $genetic->cultivation_type?->value,
                'category_id' => $genetic->category_id,
                'category_name' => $genetic->category?->name,
                'rate_cents' => $price->ratePerGramCents,
                'price_label' => $price->label(),
                'remaining_cg' => $isUnit ? ($remainingUnits ?? 0) * (int) $genetic->grams_per_unit_cg : $this->remainingCg($genetic, $location),
                'remaining_units' => $remainingUnits,
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
        $query = TillSession::query()->withoutGlobalScopes()
            ->where('location_id', $location->id)
            ->where('status', TillSessionStatus::OPEN->value);

        if ($this->terminal !== '') {
            $query->where('terminal', $this->terminal);
        }

        return $query->orderBy('opened_at')->first();
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
        if ($member->photo_path === null || $member->photo_path === '') {
            return null;
        }

        try {
            $disk = Storage::disk('documents');

            if (! $disk->exists($member->photo_path)) {
                return null;
            }

            return $disk->temporaryUrl($member->photo_path, now()->addSeconds((int) Settings::get('signed_url_ttl_seconds', 300)));
        } catch (Throwable) {
            return null; // local driver does not sign URLs — fall back to initials.
        }
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
            'cashInput', 'walletInput', 'requireOverride', 'limitBreach', 'overrideReason',
            'signaturePath', 'lastDispensationId', 'voidReason', 'flashMessage',
        ]);
        $this->idempotencyKey = (string) Str::ulid();
    }

    /** Clear the basket after a successful commit and mint the next idempotency key. */
    private function resetBasketState(): void
    {
        $this->reset([
            'basket', 'activeGeneticId', 'activeBatchId', 'weightInput', 'calculatorMode', 'unitQty',
            'cashInput', 'walletInput', 'requireOverride', 'limitBreach', 'overrideReason',
            'signaturePath',
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
