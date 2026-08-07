<?php

namespace App\Livewire\Counter;

use App\Actions\Expenses\RecordTillExpense;
use App\Actions\Stock\CommitStockTake;
use App\Actions\Till\CloseTill;
use App\Actions\Till\HandOverTill;
use App\Actions\Till\OpenTill;
use App\Actions\Till\RecordCashMovement;
use App\Actions\UnlockOperator;
use App\Enums\BatchStatus;
use App\Enums\CashMovementType;
use App\Enums\StockTakeStatus;
use App\Enums\TillSessionStatus;
use App\Enums\UnitType;
use App\Exceptions\TillAlreadyOpenException;
use App\Exceptions\TillClosedException;
use App\Livewire\Counter\Concerns\CollectsMembershipFees;
use App\Livewire\Counter\Concerns\IdentifiesOperator;
use App\Livewire\Counter\Concerns\ResolvesCounterLocation;
use App\Models\Batch;
use App\Models\ExpenseCategory;
use App\Models\Location;
use App\Models\Member;
use App\Models\StockTake;
use App\Models\StockTakeLine;
use App\Models\TillSession as TillSessionModel;
use App\Models\User;
use App\Support\CounterOperator;
use App\Support\Money;
use App\Support\Settings;
use App\Support\TerminalName;
use App\Support\TillSummary;
use App\Support\Weight;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * The till (caja) terminal — a full-page Livewire component on its own authenticated
 * route, OUTSIDE the Filament panel, sharing the tablet-first `counter` layout with
 * the check-in door. It reuses the till domain wholesale: OpenTill / RecordCashMovement
 * / CloseTill for writes and TillSummary for the live, ledger-derived figures.
 *
 * BLIND ARQUEO is the point of the screen. While the drawer is being counted the
 * expected figure is neither computed nor exposed: `$expected` / `$variance` stay
 * null public state and render() passes NO breakdown during the count. Only a
 * successful CloseTill reveals them, read back from the immutable closed session.
 */
#[Layout('components.layouts.counter')]
class TillSession extends Component
{
    use CollectsMembershipFees, IdentifiesOperator, ResolvesCounterLocation;

    /** The active location id, resolved in mount(). #[Locked] (prompt 75): the client can never retarget the counter's sede. */
    #[Locked]
    public ?string $locationId = null;

    /** Friendly state when the operator has no location at all (still a 200). */
    public bool $noLocation = false;

    /** The terminal this screen manages — the open form binds it (multi-till) or it is preset (single-till). */
    public string $terminal = '';

    /** Open form: the float in euros (converted to cents at the edge). */
    public string $floatInput = '';

    /** Cash-movement form. */
    public string $movementType = 'IN';

    public string $movementAmount = '';

    public string $movementReason = '';

    /** Petty-cash (gasto de caja) form — records a PETTY_CASH movement out of the open drawer. */
    public string $expenseAmount = '';

    public ?string $expenseCategoryId = null;

    public string $expenseNote = '';

    /** True once the operator starts the close — the live summary (incl. expected) is hidden. */
    public bool $closing = false;

    /** The blind count, entered in euros. */
    public string $countInput = '';

    /** The counted drawer cash in CENTS — set on submit, revealed with the result. */
    public ?int $counted = null;

    /** True only after a SUCCESSFUL close — gates the reveal of expected/variance. */
    public bool $countSubmitted = false;

    /** The close required a note (variance beyond tolerance) — re-prompt without revealing. */
    public bool $needsNote = false;

    public string $closeNote = '';

    // --- EOD flower reweigh (prompt 47) — a step inside the close ritual, before the cash count ---

    /** In the flower-reweigh step (blind: no expected weight shown while entering). */
    public bool $reweighing = false;

    /** The reweigh has been committed this close, so the cash count may proceed. */
    public bool $reweighDone = false;

    /** @var array<string, string> batch id => counted grams (blind entry). */
    public array $reweighCounts = [];

    /** @var array<string, bool> batch id => marked "not counted" (could not be weighed) this reweigh (prompt 91). */
    public array $reweighNotCounted = [];

    /** @var array<string, string> batch id => why a batch was not counted (required when not counted). */
    public array $reweighReasons = [];

    /** @var list<array{name: string, counted: ?string, variance: ?string, adjusted: bool, not_counted: bool, reason: ?string, repeated: bool}>|null Revealed after commit. */
    public ?array $reweighResult = null;

    /**
     * Revealed ONLY after a successful blind close (read from the closed session).
     * Null until then, so the expected figure never exists in the component's public
     * state — nor in the rendered output — while the drawer is being counted.
     */
    public ?int $expected = null;

    public ?int $variance = null;

    public ?string $flashMessage = null;

    /** success | warning | error */
    public string $flashType = 'success';

    // --- Fee collection (Cobrar cuota) — state + logic live in CollectsMembershipFees (prompt 127) ------

    public function mount(): void
    {
        abort_unless($this->userCan('till.open') || $this->userCan('till.close'), 403);

        // Resolve the counter's OWN working sede (session key counter.location_id) — never the panel
        // scope, never a silent guess. One assigned sede is adopted; several ⇒ ask (mustChooseLocation).
        $this->resolveCounterLocation();

        // Prompt 182 — pre-fill the standing float so an ordinary morning is ONE TAP.
        $this->prefillDefaultFloat();

        // Single-till sede (the default, prompt 102): there is one drawer, so preset its terminal — the open
        // form then asks only for the float, and there is no picker to get wrong.
        if ($this->locationId !== null && ! $this->multipleTills()) {
            $this->terminal = $this->defaultTerminal();

            return;
        }

        // Multi-till: resume the single open session at this sede so returning to the screen lands on its
        // summary. Ambiguous (several terminals open) → operator picks.
        if ($this->terminal === '' && $this->locationId !== null) {
            $terminals = TillSessionModel::query()->withoutGlobalScopes()
                ->where('location_id', $this->locationId)
                ->where('status', TillSessionStatus::OPEN->value)
                ->pluck('terminal');

            if ($terminals->count() === 1) {
                $this->terminal = (string) $terminals->first();
            }
        }
    }

    /**
     * The sede's standing opening float in integer cents, or null when none is configured (prompt 182).
     *
     * Read through the Settings accessor with a safe default, never a raw property — a stale or missing
     * value must degrade to "no default" and let the operator type, never throw on a counter screen at
     * nine in the morning.
     */
    public function defaultFloatCents(): ?int
    {
        $cents = (int) Settings::get('till_default_float_cents', 0);

        return $cents > 0 ? $cents : null;
    }

    /**
     * Put the standing float in the box, formatted the way the operator would type it.
     *
     * Only when the box is EMPTY: a pre-filled figure must never overwrite something a person entered, and
     * mount() runs again on a re-render. Money stays integer cents everywhere but this input, which is the
     * euro edge — `toCents()` parses it back on submit, so a decimal typed over the default rounds exactly
     * as it always did.
     */
    private function prefillDefaultFloat(): void
    {
        $cents = $this->defaultFloatCents();

        if ($cents !== null && $this->floatInput === '') {
            $this->floatInput = number_format($cents / 100, 2, ',', '');
        }
    }

    /** True when this sede runs more than one till at once (prompt 102) — resolved for the counter's OWN sede. */
    public function multipleTills(): bool
    {
        return (bool) Settings::get('multiple_tills_enabled', false, $this->locationId);
    }

    /**
     * The terminal a SINGLE-till sede opens: its first configured terminal (managed in admin, prompt 102),
     * or a sensible default when none is named yet. The name is cosmetic when there is only one drawer.
     */
    public function defaultTerminal(): string
    {
        $configured = $this->locationId !== null
            ? (Location::query()->withoutGlobalScopes()->find($this->locationId)?->terminalNames() ?? [])
            : [];

        return TerminalName::clean($configured[0] ?? 'POS-1');
    }

    // --- Open ------------------------------------------------------------------

    // --- Prompt 186: handing the drawer to the next person -------------------------------------------

    /** The handover panel is open. */
    public bool $handoverOpen = false;

    /** Counted cash at the handover, euros at the edge — parsed to integer cents before it leaves. */
    public string $handoverCounted = '';

    /** The INCOMING operator's PIN. Never kept in component state beyond the call. */
    public string $handoverPin = '';

    public string $handoverNote = '';

    public function toggleHandover(): void
    {
        $this->handoverOpen = ! $this->handoverOpen;
        $this->reset(['handoverCounted', 'handoverPin', 'handoverNote']);
    }

    /**
     * Hand the drawer over: the outgoing operator counts it, the incoming one identifies, and the session
     * continues as one arqueo.
     *
     * The count is BLIND — nothing on this screen shows the expected figure, and the variance is never
     * echoed back here. Consistent with the close-out and with prompt 47's flower reweigh, and it is the
     * whole reason the count is worth taking.
     *
     * The INCOMING operator identifies BEFORE the outgoing one is released, which is why the drawer is
     * never unheld in the ordinary flow — Toast's middle state exists in the model but the UI does not
     * produce it. `CommitDispensation` and `CommitOrder` refuse it anyway, because a gate has to be a gate.
     */
    public function handOver(): void
    {
        if (! $this->requireOperator()) {
            return;
        }

        $location = $this->resolveLocation();
        $session = $this->resolveOpenSession();
        $outgoing = CounterOperator::current();

        if ($location === null || $session === null || $outgoing === null) {
            $this->flash(__('No hay ninguna caja abierta que entregar.'), 'error');

            return;
        }

        $counted = $this->toCents($this->handoverCounted);

        if ($counted === null || $counted < 0) {
            $this->flash(__('El recuento no es válido.'), 'error');

            return;
        }

        $pin = trim($this->handoverPin);
        $this->handoverPin = '';

        if ($pin === '') {
            $this->flash(__('La persona que entra debe introducir su PIN.'), 'error');

            return;
        }

        $incoming = (new UnlockOperator)->handle($location, $pin, $this->operatorThrottleKey());

        if ($incoming === null) {
            $this->flash(__('PIN no reconocido.'), 'error');

            return;
        }

        if ($incoming->is($outgoing)) {
            $this->flash(__('La caja se entrega a otra persona, no a ti mismo.'), 'error');

            return;
        }

        try {
            (new HandOverTill)->handle($session, $counted, $outgoing, $incoming, filled($this->handoverNote) ? $this->handoverNote : null);
        } catch (AuthorizationException|RuntimeException|TillClosedException $e) {
            $this->flash($e->getMessage(), 'error');

            return;
        }

        // The drawer now belongs to the person who took it, so the counter works as them from here.
        CounterOperator::set($incoming);
        $this->reset(['handoverOpen', 'handoverCounted', 'handoverPin', 'handoverNote']);

        // Deliberately says nothing about the variance: the count was blind and stays blind until the
        // arqueo. Telling the outgoing operator now would let the next handover be counted to fit.
        $this->flash(__('Caja entregada a :name.', ['name' => $incoming->name]), 'success');
    }

    public function open(): void
    {
        $location = $this->resolveLocation();

        if ($location === null) {
            return;
        }

        // Attribution: whoever opens the drawer is PIN-identified, never the device session user.
        if (! $this->requireOperator()) {
            return;
        }

        // Single-till (default): the sede's one terminal, preset — the operator only entered a float. Multi-
        // till: the terminal the operator picked from this sede's CONFIGURED terminals (managed in admin,
        // prompt 102 — no longer free-typed at the counter). OpenTill normalises + registers it (prompt 84).
        $terminal = $this->multipleTills() ? TerminalName::clean($this->terminal) : $this->defaultTerminal();

        if ($terminal === '') {
            $this->flash(__('Elige un terminal.'), 'error');

            return;
        }

        $floatCents = $this->toCents($this->floatInput);

        if ($floatCents === null || $floatCents < 0) {
            $this->flash(__('El fondo de caja no es válido.'), 'error');

            return;
        }

        try {
            (new OpenTill)->handle($location, $terminal, $floatCents);
        } catch (TillAlreadyOpenException) {
            $this->flash(__('Ya hay una caja abierta en este terminal.'), 'error');

            return;
        }

        $this->terminal = $terminal;
        $this->floatInput = '';
        $this->flash(__('Caja abierta.'), 'success');
    }

    // --- Cash movements --------------------------------------------------------

    public function recordMovement(): void
    {
        $session = $this->resolveOpenSession();

        if ($session === null) {
            return;
        }

        // Attribution: a PIN-identified operator is required — never the device session user.
        if (! $this->requireOperator()) {
            return;
        }

        $type = CashMovementType::tryFrom($this->movementType);

        // This form records only manual drawer movements: cash IN, cash OUT, or a BANK deposit. Petty cash
        // has its OWN audited flow (recordExpense → RecordTillExpense) — never a raw movement here.
        if ($type === null || ! in_array($type, [CashMovementType::IN, CashMovementType::OUT, CashMovementType::BANKED], true)) {
            $this->flash(__('Tipo de movimiento no válido.'), 'error');

            return;
        }

        // Banking cash out to the bank is a sensitive move — gate it on cash.bank (prompt 81 wired this
        // previously-dead permission). A STAFF operator (till.open) can record IN/OUT but never a bank deposit.
        if ($type === CashMovementType::BANKED && ! $this->userCan('cash.bank')) {
            $this->flash(__('Ingresar efectivo en el banco requiere permiso.'), 'error');

            return;
        }

        $cents = $this->toCents($this->movementAmount);

        if ($cents === null || $cents <= 0) {
            $this->flash(__('El importe no es válido.'), 'error');

            return;
        }

        $reason = trim($this->movementReason);

        try {
            (new RecordCashMovement)->handle($session, $type, $cents, [
                'reason' => $reason === '' ? null : $reason,
            ]);
        } catch (TillClosedException) {
            $this->flash(__('La caja está cerrada.'), 'error');

            return;
        }

        $this->movementAmount = '';
        $this->movementReason = '';
        $this->flash(__('Movimiento registrado.'), 'success');
    }

    // --- Petty cash (gasto de caja) --------------------------------------------

    /**
     * Record a petty-cash expense against the OPEN drawer. Routes through
     * RecordTillExpense so it posts a PETTY_CASH cash movement (dropping the expected
     * drawer cash) and is audited — the screen never writes the expense directly.
     */
    public function recordExpense(): void
    {
        $session = $this->resolveOpenSession();

        if ($session === null) {
            return;
        }

        // Attribution: a PIN-identified operator is required — never the device session user.
        if (! $this->requireOperator()) {
            return;
        }

        $user = $this->currentUser();

        if ($user === null || ! $user->can('expenses.record')) {
            $this->flash(__('No tienes permiso para registrar un gasto.'), 'error');

            return;
        }

        $category = $this->expenseCategoryId !== null
            ? ExpenseCategory::query()->where('active', true)->find($this->expenseCategoryId)
            : null;

        if ($category === null) {
            $this->flash(__('Elige una categoría de gasto.'), 'error');

            return;
        }

        $cents = $this->toCents($this->expenseAmount);

        if ($cents === null || $cents <= 0) {
            $this->flash(__('El importe no es válido.'), 'error');

            return;
        }

        $note = trim($this->expenseNote);

        try {
            (new RecordTillExpense)->handle($session, $category, $cents, $user, [
                'note' => $note === '' ? null : $note,
            ]);
        } catch (TillClosedException) {
            $this->flash(__('La caja está cerrada.'), 'error');

            return;
        }

        $this->expenseAmount = '';
        $this->expenseNote = '';
        $this->expenseCategoryId = null;
        $this->flash(__('Gasto de caja registrado.'), 'success');
    }

    // --- Fee collection (Cobrar cuota) — the only path that clears unpaid_fee ----

    public function collectFee(): void
    {
        // The till screen keeps its "open drawer required" gate for BOTH methods (it IS the till). The shared
        // collectFeeThrough enforces the CASH-needs-a-till rule for callers that allow a wallet fee without one
        // (the Socios tab); here we pass the resolved open session so behaviour is unchanged (prompt 127).
        $session = $this->resolveOpenSession();
        if ($session === null) {
            $this->flash(__('No hay caja abierta en este terminal.'), 'error');

            return;
        }
        if (! $this->requireOperator()) {
            return;
        }
        $user = $this->currentUser();
        if ($user === null || ! $user->can('membership.fee.collect')) {
            $this->flash(__('No tienes permiso para cobrar cuotas.'), 'error');

            return;
        }
        $location = $this->resolveLocation();
        if ($location === null) {
            $this->flash(__('Selecciona un socio.'), 'error');

            return;
        }

        $result = $this->collectFeeThrough($session, $location, $user);
        $this->flash($result['message'], $result['type']);
    }

    // --- Blind close (arqueo) --------------------------------------------------

    /** Enter the blind count: hide the live summary and clear any prior reveal. */
    public function startClose(): void
    {
        $this->closing = true;
        $this->resetCloseState();

        // One end-of-day ritual: weigh the touched flower FIRST, then count the cash (prompt 47).
        if ($this->reweighRequired()) {
            $this->reweighing = true;
        }
    }

    public function cancelClose(): void
    {
        $this->closing = false;
        $this->resetCloseState();
    }

    /**
     * The flower batches to reweigh: OPEN, WEIGHT-type genetic, and actually TOUCHED since intake
     * (remaining_cg <> initial_cg). A never-dispensed batch (remaining === initial) is simply excluded —
     * nothing for staff to do. UNIT genetics (prerolls/edibles) and CLOSED/QUARANTINED batches never appear.
     *
     * @return \Illuminate\Support\Collection<int, Batch>
     */
    public function reweighBatches(): \Illuminate\Support\Collection
    {
        if ($this->locationId === null) {
            return collect();
        }

        return Batch::query()->withoutGlobalScopes()
            ->where('location_id', $this->locationId)
            ->where('status', BatchStatus::OPEN->value)
            ->whereColumn('remaining_cg', '<>', 'initial_cg')
            ->whereHas('genetic', fn ($q) => $q->where('unit_type', UnitType::WEIGHT->value))
            ->with('genetic')
            ->orderBy('batch_no')
            ->get();
    }

    /**
     * Exposed to the view. The reweigh is required when closing the LAST open till at the location (so it
     * fires once per location per evening, not at every terminal and never at a bar-only terminal that closes
     * first), there are touched flower batches, and no count has been committed for the location today yet.
     */
    public function reweighRequired(): bool
    {
        if ($this->reweighDone || $this->locationId === null) {
            return false;
        }

        $session = $this->resolveOpenSession();
        if ($session === null) {
            return false;
        }

        $othersOpen = TillSessionModel::query()->withoutGlobalScopes()
            ->where('location_id', $this->locationId)
            ->where('status', TillSessionStatus::OPEN->value)
            ->whereKeyNot($session->id)->exists();
        if ($othersOpen) {
            return false;
        }

        $countedToday = StockTake::query()->withoutGlobalScopes()
            ->where('location_id', $this->locationId)
            ->where('status', StockTakeStatus::COMMITTED->value)
            ->whereDate('committed_at', now())->exists();
        if ($countedToday) {
            return false;
        }

        return $this->reweighBatches()->isNotEmpty();
    }

    /**
     * Commit the blind flower count through CommitStockTake (the ONLY writer of the count + its ADJUSTMENT
     * movements). Blind, like the cash arqueo: expected weights are never shown while entering; the variances
     * are revealed only after commit. Gated on `stock.take`.
     */
    public function submitReweigh(): void
    {
        $session = $this->resolveOpenSession();
        if ($session === null) {
            return;
        }

        $user = $this->currentUser();
        if ($user === null || ! $user->can('stock.take')) {
            $this->flash(__('No tienes permiso para recontar el inventario.'), 'error');

            return;
        }

        $batches = $this->reweighBatches();
        $counts = [];

        foreach ($batches as $batch) {
            // Escape hatch (prompt 91): a jar that cannot be weighed is marked "not counted" WITH A REASON —
            // the close proceeds and its stock is left untouched. Never a silent skip, never a fake number.
            if ($this->reweighNotCounted[$batch->id] ?? false) {
                $reason = trim($this->reweighReasons[$batch->id] ?? '');
                if ($reason === '') {
                    $this->flash(__('Indica por qué no se pudo contar el lote.'), 'error');

                    return;
                }
                $counts[] = ['type' => 'batch', 'id' => $batch->id, 'not_counted' => true, 'reason' => $reason];

                continue;
            }

            $raw = trim($this->reweighCounts[$batch->id] ?? '');
            if ($raw === '' || ! is_numeric($raw) || (float) $raw < 0) {
                $this->flash(__('Introduce el peso contado de cada lote, o márcalo como no contado.'), 'error');

                return;
            }
            $counts[] = ['type' => 'batch', 'id' => $batch->id, 'counted' => Weight::fromGrams($raw)->centigrams];
        }

        $stockTake = StockTake::create([
            'organisation_id' => $session->organisation_id,
            'location_id' => $this->locationId,
            'opened_by' => $user->id,
            'opened_at' => now(),
            'status' => StockTakeStatus::OPEN,
        ]);

        $committed = (new CommitStockTake)->handle($stockTake, $counts, $user);

        // Reveal the variances (blind entry, reveal after — like the cash arqueo), read back from the
        // committed lines and matched to the batches we counted (a morph column, so via getAttribute()).
        $linesByBatch = $committed->lines()->get()
            ->keyBy(fn (StockTakeLine $line): string => (string) $line->getAttribute('countable_id'));

        $this->reweighResult = $batches->map(function (Batch $batch) use ($linesByBatch): array {
            $line = $linesByBatch->get($batch->id);

            if ($line !== null && $line->not_counted) {
                return [
                    'name' => trim($batch->genetic->name.' · '.$batch->batch_no),
                    'counted' => null, 'variance' => null, 'adjusted' => false,
                    'not_counted' => true, 'reason' => $line->not_counted_reason,
                    // Flag a jar that keeps escaping the count — exactly what a count exists to catch.
                    'repeated' => $this->wasRecentlyNotCounted($batch->id, (string) $line->stock_take_id),
                ];
            }

            $variance = $line?->variance_cg->centigrams ?? 0;
            $counted = $line?->counted_cg->centigrams ?? 0;

            return [
                'name' => trim($batch->genetic->name.' · '.$batch->batch_no),
                'counted' => Weight::fromCentigrams($counted)->formatted(),
                'variance' => Weight::fromCentigrams($variance)->formatted(),
                'adjusted' => $variance !== 0,
                'not_counted' => false, 'reason' => null, 'repeated' => false,
            ];
        })->all();

        $this->reweighDone = true;
        $this->reweighing = false;
        $this->reweighCounts = [];
        $this->reweighNotCounted = [];
        $this->reweighReasons = [];
        $this->flash(__('Recuento de flor registrado.'), 'success');
    }

    /** Toggle a batch between "counted" (needs a weight) and "not counted" (needs a reason) — prompt 91. */
    public function toggleNotCounted(string $batchId): void
    {
        $this->reweighNotCounted[$batchId] = ! ($this->reweighNotCounted[$batchId] ?? false);
        if ($this->reweighNotCounted[$batchId]) {
            $this->reweighCounts[$batchId] = ''; // a not-counted jar has no weight
        }
    }

    /**
     * Progress for the reweigh panel: how many of the touched batches have a decision (a weight OR a
     * not-counted mark). Gives staff something to anchor against on a long list (prompt 91).
     *
     * @return array{done: int, total: int}
     */
    public function reweighProgress(): array
    {
        $batches = $this->reweighBatches();
        $done = 0;
        foreach ($batches as $batch) {
            $marked = $this->reweighNotCounted[$batch->id] ?? false;
            $weighed = trim($this->reweighCounts[$batch->id] ?? '') !== '';
            if ($marked || $weighed) {
                $done++;
            }
        }

        return ['done' => $done, 'total' => $batches->count()];
    }

    /** Has this batch already been left "not counted" in a recent committed count (a jar that keeps escaping)? */
    private function wasRecentlyNotCounted(string $batchId, string $exceptStockTakeId): bool
    {
        return StockTakeLine::query()
            ->where('countable_type', Batch::class)
            ->where('countable_id', $batchId)
            ->where('not_counted', true)
            ->where('stock_take_id', '!=', $exceptStockTakeId)
            ->whereHas('stockTake', fn ($q) => $q->where('status', StockTakeStatus::COMMITTED->value)
                ->where('committed_at', '>=', now()->subDays(60)))
            ->exists();
    }

    public function submitCount(): void
    {
        $session = $this->resolveOpenSession();

        if ($session === null) {
            return;
        }

        // The flower reweigh is a required step before the cash count can close (prompt 47). Explicit +
        // recoverable — bounce back to the reweigh step with a clear reason, never a silent hang (mirror needsNote).
        if ($this->reweighRequired()) {
            $this->reweighing = true;
            $this->flash(__('Primero hay que recontar la flor.'), 'warning');

            return;
        }

        $user = $this->currentUser();

        if ($user === null || ! $user->can('till.close')) {
            $this->flash(__('No tienes permiso para cerrar la caja.'), 'error');

            return;
        }

        $counted = $this->toCents($this->countInput);

        if ($counted === null || $counted < 0) {
            $this->flash(__('El importe contado no es válido.'), 'error');

            return;
        }

        // Held so the field survives the re-prompt, but NOT yet revealed alongside any
        // expected figure — the reveal happens only once CloseTill succeeds below.
        $this->counted = $counted;

        $note = trim($this->closeNote);

        try {
            $closed = (new CloseTill)->handle($session, $counted, $user, $note === '' ? null : $note);
        } catch (TillClosedException) {
            $this->flash(__('La caja ya estaba cerrada.'), 'error');
            $this->cancelClose();

            return;
        } catch (RuntimeException) {
            // Variance beyond tolerance needs a note — re-prompt WITHOUT revealing expected.
            $this->needsNote = true;
            $this->flash(__('Hace falta una nota para justificar la diferencia.'), 'warning');

            return;
        } catch (AuthorizationException) {
            $this->flash(__('No tienes permiso para cerrar la caja.'), 'error');

            return;
        }

        // Success — NOW reveal the figures. The counted value is the one just parsed;
        // expected + variance are read back from the immutable, ledger-derived close.
        $this->countSubmitted = true;
        $this->counted = $counted;
        $this->expected = $closed->expected_cents?->cents;
        $this->variance = $closed->variance_cents?->cents;
        $this->flash(__('Caja cerrada.'), 'success');
    }

    /** Clear the reveal and start fresh (ready to open a new session). */
    public function finishClose(): void
    {
        $this->closing = false;
        $this->terminal = '';
        $this->resetCloseState();
        $this->flashMessage = null;
    }

    // --- View data -------------------------------------------------------------

    public function render(): View
    {
        $location = $this->resolveLocation();

        // After a successful blind close, keep the revealed arqueo on screen. The
        // session is now CLOSED so there is no open session to resolve.
        if ($this->countSubmitted) {
            return view('livewire.counter.till-session', [
                // The session is CLOSED here, so there is no live drawer and no trail to attribute.
                'shifts' => collect(),
                'location' => $location,
                'session' => null,
                'breakdown' => null,
                'expenseCategories' => collect(),
            ]);
        }

        $session = $this->noLocation ? null : $this->resolveOpenSession();

        // While counting the drawer NOTHING about the expected figure is computed and NO breakdown reaches
        // the view — a true blind count. That now covers the HANDOVER as well as the close (prompt 186):
        // the handover panel sits on the ordinary till screen, which renders "efectivo esperado en el
        // cajón" a few centimetres above it, so an operator could simply read the answer before counting.
        // The close-out withholds the breakdown by going through `closing`; the handover has to withhold it
        // the same way or its count is blind in name only. Found by LOOKING at the screenshot, not by a
        // test — which is exactly the accident the prompt predicted from reusing the close-out's parts.
        $breakdown = ($session !== null && ! $this->closing && ! $this->handoverOpen)
            ? TillSummary::breakdown($session)
            : null;

        // Petty-cash categories, only when the drawer is open and not being counted.
        $expenseCategories = ($session !== null && ! $this->closing)
            ? ExpenseCategory::query()->where('active', true)->orderBy('name')->get()
            : collect();

        // Fee collection view data — live, never stored on the component.
        $feeMember = $this->feeMemberId !== null ? Member::query()->find($this->feeMemberId) : null;
        $feeMembership = ($feeMember !== null && $location !== null)
            ? $this->outstandingMembership($feeMember, $location)
            : null;

        return view('livewire.counter.till-session', [
            // Prompt 186 — the day's attribution trail. ONE row on a single-operator day, which is why such
            // a club notices nothing: the list only renders when the drawer actually changed hands.
            'shifts' => $session?->shifts()->with('openedBy')->get() ?? collect(),
            'location' => $location,
            'session' => $session,
            'breakdown' => $breakdown,
            'expenseCategories' => $expenseCategories,
            'feeResults' => $this->feeSearchResults(),
            'feeMember' => $feeMember,
            'feeOwedCents' => $feeMembership !== null ? $this->owedCents($feeMembership) : null,
            // EOD flower reweigh (prompt 47) — the in-scope batches, only while in that step.
            'reweighBatches' => $this->reweighing ? $this->reweighBatches() : collect(),
            // Configured terminals for the open-form picker (prompt 84).
            'terminals' => $location?->terminalNames() ?? [],
        ]);
    }

    /** Money for display (integer cents), via the shared value object. */
    public function money(int $cents): string
    {
        return Money::fromCents($cents)->formatted();
    }

    // --- Resolvers & helpers ---------------------------------------------------

    private function resolveLocation(): ?Location
    {
        return $this->locationId !== null ? Location::query()->find($this->locationId) : null;
    }

    /** The OPEN session for this terminal at the active location (live, unscoped). */
    private function resolveOpenSession(): ?TillSessionModel
    {
        if ($this->locationId === null || $this->terminal === '') {
            return null;
        }

        return TillSessionModel::query()->withoutGlobalScopes()
            ->where('location_id', $this->locationId)
            ->where('terminal', $this->terminal)
            ->where('status', TillSessionStatus::OPEN->value)
            ->first();
    }

    /** Parse a euros string from the edge to integer cents, or null when invalid. */
    private function toCents(string $euros): ?int
    {
        $euros = trim($euros);

        if ($euros === '') {
            return null;
        }

        try {
            return Money::fromEuros($euros)->cents;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function resetCloseState(): void
    {
        $this->countSubmitted = false;
        $this->needsNote = false;
        $this->countInput = '';
        $this->counted = null;
        $this->closeNote = '';
        $this->expected = null;
        $this->variance = null;
        $this->reweighing = false;
        $this->reweighDone = false;
        $this->reweighCounts = [];
        $this->reweighResult = null;
    }

    private function userCan(string $permission): bool
    {
        return $this->currentUser()?->can($permission) ?? false;
    }

    /** Whether this operator may bank cash (cash.bank) — the view hides the BANK option otherwise (prompt 81). */
    public function canBankCash(): bool
    {
        return $this->userCan('cash.bank');
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
