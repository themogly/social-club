<?php

namespace App\Livewire\Counter;

use App\Actions\Counter\SignInOperator;
use App\Actions\Expenses\RecordTillExpense;
use App\Actions\RecordAuditLog;
use App\Actions\Staff\ClockIn;
use App\Actions\Staff\ClockOut;
use App\Actions\Staff\UndoTillClockEvent;
use App\Actions\Stock\CommitStockTake;
use App\Actions\Till\CloseTill;
use App\Actions\Till\HandOverTill;
use App\Actions\Till\OpenTill;
use App\Actions\Till\RecordCashMovement;
use App\Actions\UnlockOperator;
use App\Enums\BatchStatus;
use App\Enums\CashMovementType;
use App\Enums\CashPot;
use App\Enums\CloseCountReason;
use App\Enums\StaffClockSource;
use App\Enums\StockTakeStatus;
use App\Enums\TillSessionStatus;
use App\Enums\UnitType;
use App\Exceptions\TillAlreadyOpenException;
use App\Exceptions\TillClosedException;
use App\Http\Middleware\RequireOpenTill;
use App\Livewire\Counter\Concerns\IdentifiesOperator;
use App\Livewire\Counter\Concerns\ReportsSystemErrors;
use App\Livewire\Counter\Concerns\ResolvesCounterLocation;
use App\Models\Batch;
use App\Models\ExpenseCategory;
use App\Models\Location;
use App\Models\StaffClockEvent;
use App\Models\StockTake;
use App\Models\StockTakeLine;
use App\Models\TillSession as TillSessionModel;
use App\Models\User;
use App\Support\BusinessDay;
use App\Support\CounterOperator;
use App\Support\CounterScreens;
use App\Support\ManagerApproval;
use App\Support\Money;
use App\Support\NumberFormat;
use App\Support\Settings;
use App\Support\TerminalName;
use App\Support\TillSummary;
use App\Support\Weight;
use App\Support\WorkedHours;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use PDOException;
use RuntimeException;
use Throwable;

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
    /** Prompt 338 — *Preguntar*: the opener to ask «¿Fichar entrada ahora?» on the screen they land on. */
    public const CLOCK_IN_OFFER = 'counter_clock_in_offer';

    use IdentifiesOperator, ReportsSystemErrors, ResolvesCounterLocation;

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

    /** Prompt 349 — which cash pot a movement belongs to (with *Botes de efectivo separados* on). */
    public string $movementPot = 'DISPENSARY';

    /**
     * Prompt 349 — at the close, the bar and fees pots: counted now (with an amount) or not counted tonight.
     *
     * @var array<string, string>
     */
    public array $potCountInput = ['BAR' => '', 'FEES' => ''];

    /** @var array<string, bool> */
    public array $potCountNow = ['BAR' => false, 'FEES' => false];

    /**
     * Revealed after the close: each optional pot's expected / counted / variance.
     *
     * @var array<string, array{expected: int, counted: ?int, variance: ?int}>
     */
    public array $potResults = [];

    public string $movementAmount = '';

    public string $movementReason = '';

    /** The session just closed, for the revealed arqueo's itemised petty cash (prompt 265). Server-set only. */
    #[Locked]
    public ?string $closedSessionId = null;

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

    /** Prompt 281 — after closing the till, offer the closer "¿Fichar salida ahora?" when they have an open period. */
    public bool $clockOutOffer = false;

    /** Prompt 312 — the closer's automatic clock-out time ("23:04") while *Deshacer* is offered; null otherwise. */
    public ?string $clockedOutAt = null;

    /** Prompt 312 — the colleague whose PIN is being asked for, and that PIN (never kept past the check). */
    public ?string $clockOutOtherId = null;

    public string $otherPin = '';

    public ?string $otherFeedback = null;

    /** The close required a note (variance beyond tolerance) — re-prompt without revealing. */
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

    /**
     * Prompt 360 — the count was off (a jar beyond the tolerance, or «No contado»), so ONE question is on screen before it
     * commits: «El recuento no cuadra — ¿qué ha pasado?». Blind: which jar, and by how much, is revealed after, as before.
     */
    public bool $reweighAsking = false;

    /** @var list<array{name: string, counted: ?string, variance: ?string, adjusted: bool, not_counted: bool, reason: ?string, repeated: bool}>|null Revealed after commit. */
    public ?array $reweighResult = null;

    /** Prompt 360 — the count's one answer, shown beside the revealed variances (null when nothing was off). */
    public ?string $reweighReason = null;

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

    /**
     * WHERE the flash renders (prompt 279): null is the shared slot at the top of the screen; otherwise the form
     * card that produced it — `movement`, `expense`, `handover`, `reweigh`, `count` — right by its button.
     *
     * At iPad landscape the operator has scrolled down to a form before tapping, so a top-of-page answer landed
     * 300–600px above the viewport and a working "Registrar movimiento" read as broken (and got tapped twice).
     * Prompt 202's rule: the result shows where the action happened, and only there. Set ONLY by `flash()`, from
     * the action's own `$feedbackIn`, so the slot can never outlive the action that chose it. Server-set only.
     */
    #[Locked]
    public ?string $flashAt = null;

    /**
     * The card the running action answers in. Private, so Livewire does not persist it: each request starts at
     * null (the top), and an action opts its own flashes into its card. Any flash raised outside such an action
     * — a PIN sign-in, a whole-screen outcome — therefore goes to the top without anyone having to remember.
     */
    private ?string $feedbackIn = null;

    /**
     * Bumped by every `flash()` and joined to the message's key (prompt 279). Without it the same confirmation
     * twice — two 10,00 € entradas — morphed onto the first one's already-faded element and showed nothing.
     */
    #[Locked]
    public int $flashSeq = 0;

    public function mount(): void
    {
        abort_unless($this->deviceCan('till.open') || $this->deviceCan('till.close'), 403);

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
            $this->floatInput = NumberFormat::decimal($cents / 100, 2); // a point, like every figure (prompt 316)
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
        $this->feedbackIn = 'handover';

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
            $this->flash($this->pinFailureMessage(), 'error');

            return;
        }

        if ($incoming->is($outgoing)) {
            $this->flash(__('La caja se entrega a otra persona, no a ti mismo.'), 'error');

            return;
        }

        try {
            (new HandOverTill)->handle($session, $counted, $outgoing, $incoming, filled($this->handoverNote) ? $this->handoverNote : null);
        } catch (PDOException $e) {
            $this->systemError($e);

            return;
        } catch (AuthorizationException|RuntimeException|TillClosedException $e) {
            $this->flash($e->getMessage(), 'error');

            return;
        }

        // The drawer now belongs to the person who took it, so the counter works as them from here — and so does the
        // session (prompt 270): a handover is a PIN sign-in like any other, never a person switch under the old login.
        (new SignInOperator)->handle($incoming, $location);
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

        // Prompt 266 — opening the drawer is the operator's own permission (255), not only the screen's mount gate.
        if (! $this->userCan('till.open')) {
            $this->flash(__('Tu usuario no puede abrir la caja.'), 'error');

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
            $opened = (new OpenTill)->handle($location, $terminal, $floatCents);
        } catch (TillAlreadyOpenException) {
            // Two devices, one drawer (prompt 236): another terminal opened this sede's till between the
            // redirect and this tap. The precondition is now MET, so this is not an error to correct — follow
            // the operator to where they were heading, exactly as a successful open would.
            $this->continueToIntended();

            return;
        }

        $this->terminal = $terminal;
        $this->floatInput = '';
        $this->clockInOpener($opened);

        // Prompt 236 — land the operator on the screen they were sent here from, or the sede's configured
        // landing. Same consumer as the two-device continue below, so the round trip is written once.
        $this->continueToIntended();
    }

    /**
     * Prompt 338 — the OPENER, if not clocked in, is clocked in at the open's own time: source TILL_OPEN (their PIN just
     * opened the till, so it is their own act, as 312's close). *Automático*: at once, with a 2-minute *Deshacer*;
     * *Preguntar*: "¿Fichar entrada ahora?". The screen they land on shows either (the counter chrome, which follows every
     * redirect). Nobody else is ever clocked in. A failure leaves them unclocked and never touches the open.
     */
    private function clockInOpener(TillSessionModel $opened): void
    {
        $operator = CounterOperator::current();
        $location = $this->resolveLocation();
        if ($operator === null || $location === null || WorkedHours::openPeriodFor($operator) !== null) {
            return;
        }

        if (Settings::get('till_close_clock_out', 'auto', $this->locationId) === 'ask') {
            session([self::CLOCK_IN_OFFER => $operator->id]);

            return;
        }

        try {
            $in = (new ClockIn)->handle($operator, $location, $operator, StaffClockSource::TILL_OPEN, $opened->opened_at);
        } catch (AuthorizationException|DomainException|InvalidArgumentException) {
            return;
        }
        session([CounterOperator::CLOCK_UNDO => $in->id]);
        $this->dispatch('counter-clock-state', open: true);
    }

    /**
     * The counter URL the front-door guard stashed when it redirected the operator here, if one is still
     * pending. A PEEK, not a pull: render() calls it every cycle to decide whether to offer "Continuar", so
     * consuming it here would drop the destination before the operator taps.
     */
    public function pendingContinueUrl(): ?string
    {
        $url = session(RequireOpenTill::INTENDED_KEY);

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Follow the operator to where they were heading. Two-device case (prompt 236): device B is sent here with
     * no till open; before it opens one, device A opens the sede's shared drawer. B's next render flips to the
     * open-session branch and offers this, so B continues to its intended screen rather than opening a second
     * till. Also the tail of a successful `open()`. Consumes the stashed URL so it cannot fire again next time.
     */
    public function continueToIntended(): void
    {
        $url = session()->pull(RequireOpenTill::INTENDED_KEY);

        $this->redirect(is_string($url) && $url !== '' ? $url : $this->landingUrl());
    }

    /**
     * Where "continue" lands when there was no intended screen — the counter's own per-user landing (prompt
     * 189's `counter_landing`, resolved PER USER so a `till.open`-only operator is never sent to a screen they
     * cannot open). One resolver, not a second copy of the rule.
     */
    private function landingUrl(): string
    {
        $route = CounterScreens::landingRouteFor($this->deviceUser()) ?? 'counter.home';

        return route($route);
    }

    // --- Cash movements --------------------------------------------------------

    public function recordMovement(): void
    {
        $this->feedbackIn = 'movement';

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
                // Prompt 349 — the pot it comes out of (or goes into); only meaningful when the session keeps pots.
                'pot' => $session->separate_pots ? (CashPot::tryFrom($this->movementPot) ?? CashPot::DISPENSARY) : CashPot::DISPENSARY,
            ]);
        } catch (TillClosedException) {
            $this->flash(__('La caja está cerrada.'), 'error');

            return;
        }

        $this->movementAmount = '';
        $this->movementReason = '';
        $this->movementPot = 'DISPENSARY';
        // Named, not just acknowledged: the amount field is now blank, so "Movimiento registrado." left the
        // operator with no way to check what they had posted (prompt 202) — and the type with it (prompt 279).
        $this->flash(__('Movimiento registrado: :amount (:type).', [
            'amount' => Money::fromCents($cents)->formatted(),
            'type' => $type->shortLabel(),
        ]), 'success');
    }

    // --- Petty cash (gasto de caja) --------------------------------------------

    /**
     * Record a petty-cash expense against the OPEN drawer. Routes through
     * RecordTillExpense so it posts a PETTY_CASH cash movement (dropping the expected
     * drawer cash) and is audited — the screen never writes the expense directly.
     */
    public function recordExpense(): void
    {
        $this->feedbackIn = 'expense';

        $session = $this->resolveOpenSession();

        if ($session === null) {
            return;
        }

        // Attribution: a PIN-identified operator is required — never the device session user.
        if (! $this->requireOperator()) {
            return;
        }

        $user = $this->counterActor();

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
        $this->flash(__('Gasto de caja registrado: :amount.', ['amount' => Money::fromCents($cents)->formatted()]), 'success');
    }

    // --- Blind close (arqueo) --------------------------------------------------

    /** Enter the blind count: hide the live summary and clear any prior reveal. */
    public function startClose(): void
    {
        $this->closing = true;
        // Prompt 349 — each optional pot starts on its sede's «Contar cada noche».
        $this->potCountNow = [
            'BAR' => (bool) Settings::get('count_bar_nightly', false, $this->locationId),
            'FEES' => (bool) Settings::get('count_fees_nightly', false, $this->locationId),
        ];
        $this->potCountInput = ['BAR' => '', 'FEES' => ''];
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
     * @return Collection<int, Batch>
     */
    public function reweighBatches(): Collection
    {
        if ($this->locationId === null) {
            return collect();
        }

        return Batch::query()->withoutGlobalScopes()
            ->where('location_id', $this->locationId)
            ->where('status', BatchStatus::OPEN->value)
            // Touched today, counting the sealed reserve as untouched stock (359): a batch whose bags merely sit in the back
            // is not added to the evening's count — no new end-of-day work.
            ->whereRaw('remaining_cg + reserve_cg <> initial_cg')
            ->whereHas('genetic', fn ($q) => $q->where('unit_type', UnitType::WEIGHT->value))
            ->with('genetic')
            ->get()
            // Prompt 282 / 298 — listed as the club reads them: strain, then the club's name, then the lote's place in the strain.
            ->sortBy([
                fn (Batch $a, Batch $b): int => strcasecmp((string) $a->genetic?->name, (string) $b->genetic?->name),
                fn (Batch $a, Batch $b): int => strcasecmp((string) $a->label, (string) $b->label),
                fn (Batch $a, Batch $b): int => ($a->lote_seq ?? 0) <=> ($b->lote_seq ?? 0),
            ])
            ->values();
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

        // "Today" is the sede's business day (prompt 275), not the UTC calendar date.
        $location = Location::query()->withoutGlobalScopes()->find($this->locationId);
        [$dayStart, $dayEnd] = $location !== null ? BusinessDay::window($location) : [now()->startOfDay(), now()->startOfDay()->addDay()];
        $countedToday = StockTake::query()->withoutGlobalScopes()
            ->where('location_id', $this->locationId)
            ->where('status', StockTakeStatus::COMMITTED->value)
            ->where('committed_at', '>=', $dayStart)->where('committed_at', '<', $dayEnd)->exists();
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
    public function submitReweigh(?string $reasonKey = null, ?string $otherText = null): void
    {
        $this->commitReweigh($reasonKey, $otherText, withoutReason: false);
    }

    /**
     * Prompt 366 — «Seguir sin motivo»: the count was off and nobody knows why. It commits all the same (the close never
     * waits on an answer) and is audited `stock.count_unexplained`, so the owner sees it on the till page and in the log.
     */
    public function submitReweighWithoutReason(): void
    {
        $this->commitReweigh(null, null, withoutReason: true);
    }

    private function commitReweigh(?string $reasonKey, ?string $otherText, bool $withoutReason): void
    {
        $this->feedbackIn = 'reweigh';

        $session = $this->resolveOpenSession();
        if ($session === null) {
            return;
        }

        // A stock take is a write attributed to a person (prompt 255) — never run with nobody identified.
        if (! $this->requireOperator()) {
            return;
        }

        $user = $this->counterActor();
        if ($user === null || ! $user->can('stock.take')) {
            $this->flash(__('No tienes permiso para recontar el inventario.'), 'error');

            return;
        }

        $batches = $this->reweighBatches();
        $counts = [];

        foreach ($batches as $batch) {
            // Escape hatch (prompt 91): a jar that cannot be weighed is marked "not counted" — the close proceeds and its
            // stock is left untouched. Never a silent skip, never a fake number. Prompt 360: its reason is the count's ONE
            // answer below, not a box per jar.
            if ($this->reweighNotCounted[$batch->id] ?? false) {
                $counts[] = ['type' => 'batch', 'id' => $batch->id, 'not_counted' => true];

                continue;
            }

            $raw = trim($this->reweighCounts[$batch->id] ?? '');
            // Prompt 257 — the one unambiguous reading (Weight::canonicalGrams): "1.000" is refused, never
            // counted as one gram, and the counter's comma decimal ("412,5") is accepted like the POS pad.
            if (Weight::canonicalGrams($raw) === null) {
                $this->flash(__('Introduce el peso contado de cada lote, o márcalo como no contado.'), 'error');

                return;
            }
            $counts[] = ['type' => 'batch', 'id' => $batch->id, 'counted' => Weight::fromGrams($raw)->centigrams];
        }

        // Prompt 360 — Ben: "when the staff weigh at the end of the day, it just gives them one reason to fill out if wrong".
        // Nothing off ⇒ no question. Off ⇒ one answer covers the whole count; a `reasons.optional` holder (356) is not
        // asked and «Aprobado por responsable» is recorded. The server decides, so the screen cannot skip it.
        $reason = null;
        $unexplained = false;
        if (CommitStockTake::closeCountIsOff($counts, $this->locationId)) {
            $reason = ManagerApproval::allows($user) ? ManagerApproval::reason() : $this->closeCountReason($reasonKey, $otherText);
            if ($reason === null && ! $withoutReason) {
                $this->reweighAsking = true;

                return;
            }
            $unexplained = $reason === null;
        }

        try {
            $stockTake = StockTake::create([
                'organisation_id' => $session->organisation_id,
                'location_id' => $this->locationId,
                'opened_by' => $user->id,
                'opened_at' => now(),
                'status' => StockTakeStatus::OPEN,
            ]);

            $committed = (new CommitStockTake)->handle($stockTake, $counts, $user, $reason);

            if ($unexplained) {
                (new RecordAuditLog)->handle('stock.count_unexplained', $committed, null, [
                    'location_id' => $this->locationId,
                    'till_session_id' => $session->id,
                    'batches' => count($counts),
                    'not_counted' => count(array_filter($counts, fn (array $count): bool => (bool) ($count['not_counted'] ?? false))),
                ]);
            }
        } catch (PDOException $e) {
            $this->systemError($e, __('No se pudo registrar el recuento por un error del sistema. Ya está avisado. Inténtalo de nuevo o avisa al responsable.'));

            return;
        }

        // Reveal the variances (blind entry, reveal after — like the cash arqueo), read back from the
        // committed lines and matched to the batches we counted (a morph column, so via getAttribute()).
        $linesByBatch = $committed->lines()->get()
            ->keyBy(fn (StockTakeLine $line): string => (string) $line->getAttribute('countable_id'));

        $this->reweighResult = $batches->map(function (Batch $batch) use ($linesByBatch): array {
            $line = $linesByBatch->get($batch->id);

            if ($line !== null && $line->not_counted) {
                return [
                    'name' => $batch->displayName(),
                    'counted' => null, 'variance' => null, 'adjusted' => false,
                    'not_counted' => true, 'reason' => $line->not_counted_reason,
                    // Flag a jar that keeps escaping the count — exactly what a count exists to catch.
                    'repeated' => $this->wasRecentlyNotCounted($batch->id, (string) $line->stock_take_id),
                ];
            }

            $variance = $line?->variance_cg->centigrams ?? 0;
            $counted = $line?->counted_cg->centigrams ?? 0;

            return [
                'name' => $batch->displayName(),
                'counted' => Weight::fromCentigrams($counted)->formatted(),
                'variance' => Weight::fromCentigrams($variance)->formatted(),
                'adjusted' => $variance !== 0,
                'not_counted' => false, 'reason' => null, 'repeated' => false,
                // Prompt 359 — a bag opened into the jar without «Rellenar», absorbed by the count (said, never asked).
                'unrecorded_topup' => self::topUpLabel($line),
            ];
        })->all();

        $this->reweighReason = $reason;
        $this->reweighDone = true;
        $this->reweighing = false;
        $this->reweighAsking = false;
        $this->reweighCounts = [];
        $this->reweighNotCounted = [];
        $this->flash(__('Recuento de flor registrado.'), 'success');
    }

    /** Prompt 360 — the answer as stored: a quick pick's label, or «Otro» with its short line (both required). */
    private function closeCountReason(?string $key, ?string $otherText): ?string
    {
        $pick = CloseCountReason::tryFrom((string) $key);
        if ($pick === CloseCountReason::OTHER) {
            $text = mb_substr(trim((string) $otherText), 0, 120);

            return $text === '' ? null : $text;
        }

        return $pick?->label();
    }

    /** Prompt 359 — «Rellenado sin registrar: …» for a line whose count absorbed a forgotten top-up, else null. */
    private static function topUpLabel(?StockTakeLine $line): ?string
    {
        $absorbed = $line === null ? 0 : (int) $line->getRawOriginal('unrecorded_topup_cg');

        return $absorbed > 0 ? Weight::fromCentigrams($absorbed)->formatted() : null;
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
        $this->feedbackIn = 'count';

        $session = $this->resolveOpenSession();

        if ($session === null) {
            return;
        }

        // The flower reweigh is a required step before the cash count can close (prompt 47). Explicit +
        // recoverable — bounce back to the reweigh step with a clear reason, never a silent hang.
        if ($this->reweighRequired()) {
            $this->reweighing = true;
            $this->feedbackIn = 'reweigh'; // the screen is now the recount — say why, inside it
            $this->flash(__('Primero hay que recontar la flor.'), 'warning');

            return;
        }

        // Closing the till is the operator's act, and it is attributed (`closed_by`) — prompt 255 found it ran
        // with nobody identified, e.g. straight after an idle lock.
        if (! $this->requireOperator()) {
            return;
        }

        $user = $this->counterActor();

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

        // Prompt 349 — the bar and fees pots: a count for each one counted now, null for "no se cuenta hoy".
        $potCounts = [];
        if ($session->separate_pots) {
            foreach (CashPot::optional() as $pot) {
                if (! ($this->potCountNow[$pot->value] ?? false)) {
                    continue;
                }
                $potCents = $this->toCents($this->potCountInput[$pot->value] ?? '');
                if ($potCents === null || $potCents < 0) {
                    $this->flash(__('El importe contado de :pot no es válido.', ['pot' => $pot->label()]), 'error');

                    return;
                }
                $potCounts[$pot->value] = $potCents;
            }
        }

        try {
            $closed = app(CloseTill::class)->handle($session, $counted, $user, $note === '' ? null : $note, $potCounts);
        } catch (TillClosedException) {
            $this->flash(__('La caja ya estaba cerrada.'), 'error');
            $this->cancelClose();

            return;
        } catch (AuthorizationException) {
            $this->flash(__('No tienes permiso para cerrar la caja.'), 'error');

            return;
        } catch (InvalidArgumentException) {
            $this->flash(__('El importe contado no es válido.'), 'error');

            return;
        } catch (Throwable $e) {
            // Prompt 366 — a difference never refuses a close, so anything else is the system's fault: report it and say
            // so. (A broad RuntimeException catch used to call every failure "write a note" — Shane's close that would
            // not shut "even with a note in the box".)
            $this->systemError($e, __('No se pudo cerrar la caja por un error del sistema. Ya está avisado. Inténtalo de nuevo o avisa al responsable.'));

            return;
        }

        // Success — NOW reveal the figures. The counted value is the one just parsed;
        // expected + variance are read back from the immutable, ledger-derived close.
        $this->countSubmitted = true;
        $this->closedSessionId = $closed->id; // prompt 265 — the arqueo shows this session's petty cash, itemised
        $this->counted = $counted;
        $this->expected = $closed->expected_cents?->cents;
        $this->variance = $closed->variance_cents?->cents;
        $this->potResults = [];
        if ($closed->separate_pots) {
            foreach (CashPot::optional() as $pot) {
                $this->potResults[$pot->value] = [
                    'expected' => (int) $closed->getRawOriginal($pot->column().'_expected_cents'),
                    'counted' => $closed->getRawOriginal($pot->column().'_counted_cents') !== null ? (int) $closed->getRawOriginal($pot->column().'_counted_cents') : null,
                    'variance' => $closed->getRawOriginal($pot->column().'_variance_cents') !== null ? (int) $closed->getRawOriginal($pot->column().'_variance_cents') : null,
                ];
            }
        }
        $this->flash(__('Caja cerrada.'), 'success');
        $this->clockOutCloser($user, $closed);
    }

    /**
     * Prompt 312 — the CLOSER, if clocked in, is clocked out at the close's own time: source TILL_CLOSE, no question (their
     * PIN just closed the till, so it is still their own act), with a 2-minute *Deshacer*. A sede on *Preguntar* keeps
     * 281's question. Nobody else is ever clocked out here — they are listed and use their own PIN. A failure leaves the
     * period open (the forgotten-clock-out flow catches it) and never touches the close.
     */
    private function clockOutCloser(User $user, TillSessionModel $closed): void
    {
        $this->clockOutOffer = false;
        $this->clockedOutAt = null;

        if (WorkedHours::openPeriodFor($user) === null) {
            return;
        }
        if (Settings::get('till_close_clock_out', 'auto', $this->locationId) === 'ask') {
            $this->clockOutOffer = true;

            return;
        }

        try {
            $out = (new ClockOut)->handle($user, $user, StaffClockSource::TILL_CLOSE, $closed->closed_at);
        } catch (AuthorizationException|DomainException|InvalidArgumentException) {
            return;
        }
        session([CounterOperator::CLOCK_UNDO => $out->id]);
        $this->clockedOutAt = local_datetime($out->occurred_at, 'H:i', $this->resolveLocation());
        $this->dispatch('counter-clock-state', open: false);
    }

    /** *Deshacer* — the closer takes their automatic clock-out back (an ANNUL; the period is open again). */
    public function undoClockOut(): void
    {
        $operator = CounterOperator::current();
        $this->clockedOutAt = null;

        try {
            if ($operator === null) {
                throw new DomainException(__('Ya no se puede deshacer la salida. Si hace falta, corrígela desde el registro de jornada.'));
            }
            (new UndoTillClockEvent)->handle($operator);
            $this->dispatch('counter-clock-state', open: true);
            $this->flash(__('Salida deshecha: sigues con la jornada abierta.'), 'success');
        } catch (DomainException $e) {
            $this->flash($e->getMessage(), 'warning');
        }
    }

    /**
     * Prompt 312 — the others still clocked in at this sede, for the close screen: first name and since when, nothing
     * else about them (no hours).
     *
     * @return list<array{id: string, name: string, since: string}>
     */
    public function stillClockedIn(): array
    {
        $location = $this->resolveLocation();
        if ($location === null) {
            return [];
        }

        return array_map(fn (StaffClockEvent $in): array => [
            'id' => (string) $in->user_id,
            'name' => Str::before(trim((string) $in->user?->name), ' '),
            'since' => local_datetime($in->occurred_at, 'H:i', $location),
        ], WorkedHours::openPeriodsAt($location, CounterOperator::current()));
    }

    /** *Fichar salida* beside a colleague's name: their PIN is asked for next. */
    public function startClockOutFor(string $userId): void
    {
        $this->otherPin = '';
        $this->otherFeedback = null;
        $this->clockOutOtherId = in_array($userId, array_column($this->stillClockedIn(), 'id'), true) ? $userId : null;
    }

    public function cancelClockOutFor(): void
    {
        $this->clockOutOtherId = null;
        $this->otherPin = '';
        $this->otherFeedback = null;
    }

    /**
     * Their OWN PIN clocks them out — the same pad rule and throttle as 281's *Fichar salida* — and it must be the PIN of
     * the person on that row. It never signs them in at the counter: the closer stays the operator.
     */
    public function confirmClockOutFor(): void
    {
        $location = $this->resolveLocation();
        $pin = trim($this->otherPin);
        $this->otherPin = '';
        if ($location === null || $this->clockOutOtherId === null || $pin === '') {
            return;
        }

        $matched = (new UnlockOperator)->handle($location, $pin, $this->operatorThrottleKey());
        if ($matched === null) {
            $this->otherFeedback = $this->pinFailureMessage();

            return;
        }
        if ((string) $matched->getKey() !== $this->clockOutOtherId) {
            $this->otherFeedback = __('Ese PIN no es de esta persona: cada persona ficha su propia salida.');

            return;
        }

        try {
            (new ClockOut)->handle($matched, $matched, StaffClockSource::PIN);
        } catch (AuthorizationException|DomainException|InvalidArgumentException $e) {
            $this->otherFeedback = $e->getMessage();

            return;
        }
        $this->flash(__('Salida fichada: :name.', ['name' => Str::before(trim((string) $matched->name), ' ')]), 'success');
        $this->cancelClockOutFor();
    }

    /**
     * The closed session's petty cash — total and items — from the one source (`TillSummary::breakdown`), scoped to
     * this counter's sede.
     *
     * @return array{total: int, items: list<array{category: string, note: ?string, amount_cents: int, recorded_by: string, at: string}>}|null
     */
    private function closedPettyCash(string $sessionId): ?array
    {
        $closed = TillSessionModel::query()->withoutGlobalScopes()->where('location_id', $this->locationId)->find($sessionId);

        if ($closed === null) {
            return null;
        }

        $breakdown = TillSummary::breakdown($closed);

        return ['total' => -$breakdown['petty_cash'], 'items' => $breakdown['petty_cash_items']];
    }

    /** Clear the reveal and start fresh (ready to open a new session). */
    /** "Sí" — clock out at now, source TILL_CLOSE, no second PIN: the close itself was just authorised by them. */
    public function clockOutAfterClose(): void
    {
        $operator = CounterOperator::current();
        $this->clockOutOffer = false;

        if ($operator === null) {
            return;
        }

        try {
            (new ClockOut)->handle($operator, $operator, StaffClockSource::TILL_CLOSE);
            $this->dispatch('counter-clock-state', open: false);
            $this->flash(__('Salida fichada.'), 'success');
        } catch (DomainException|InvalidArgumentException $e) {
            $this->flash($e->getMessage(), 'warning');
        }
    }

    /** "No, sigo trabajando." */
    public function dismissClockOutOffer(): void
    {
        $this->clockOutOffer = false;
    }

    public function finishClose(): void
    {
        $this->closedSessionId = null;
        $this->closing = false;
        $this->terminal = '';
        $this->resetCloseState();
        $this->flashMessage = null;
    }

    // --- View data -------------------------------------------------------------

    /**
     * Prompt 349 — what the bar and fees pots would open with at the terminal being opened (their last close's count, or
     * expected if not counted); null when this sede does not keep pots.
     *
     * @return array{bar: int, fees: int}|null
     */
    public function carriedPots(): ?array
    {
        $location = $this->resolveLocation();
        if ($location === null || ! (bool) Settings::get('separate_cash_pots', false, (string) $location->getKey())) {
            return null;
        }

        $terminal = $this->multipleTills() ? TerminalName::clean($this->terminal) : $this->defaultTerminal();

        return OpenTill::carriedOpenings($location, TerminalName::key($terminal));
    }

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
                'uncountedSince' => [],
                'expenseCategories' => collect(),
                // Prompt 265 — after the reveal only (never on the blind count): the closed session's petty cash, itemised.
                'closedPetty' => $this->closedSessionId !== null ? $this->closedPettyCash($this->closedSessionId) : null,
                'flashSlot' => null,
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
        // Prompt 349 — since when the bar / fees pots have gone uncounted (shown beside their expected, never in a blind count).
        $uncounted = $breakdown !== null && $breakdown['separate_pots']
            ? collect(CashPot::optional())->mapWithKeys(fn (CashPot $pot): array => [$pot->value => TillSummary::uncountedSince($session, $pot)])->all()
            : [];

        // Petty-cash categories, only when the drawer is open and not being counted.
        $expenseCategories = ($session !== null && ! $this->closing)
            ? ExpenseCategory::query()->where('active', true)->orderBy('name')->get()
            : collect();

        return view('livewire.counter.till-session', [
            // Prompt 186 — the day's attribution trail. ONE row on a single-operator day, which is why such
            // a club notices nothing: the list only renders when the drawer actually changed hands.
            'shifts' => $session?->shifts()->with('openedBy')->get() ?? collect(),
            'location' => $location,
            'session' => $session,
            'breakdown' => $breakdown,
            'uncountedSince' => $uncounted,
            'closedPetty' => null,
            'expenseCategories' => $expenseCategories,
            // EOD flower reweigh (prompt 47) — the in-scope batches, only while in that step.
            'reweighBatches' => $this->reweighing ? $this->reweighBatches() : collect(),
            // Configured terminals for the open-form picker (prompt 84).
            'terminals' => $location?->terminalNames() ?? [],
            // Prompt 279 — where the flash renders: beside the button that raised it, or the top.
            'flashSlot' => $this->flashSlot($session),
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

    /**
     * Parse a typed euros string to integer cents, or null when blank/ambiguous — the strict rule (prompt 271). The
     * blind count used to go through `Money::fromEuros`, so a drawer counted as "1.250" closed at €1,25, immutably.
     */
    private function toCents(string $euros): ?int
    {
        return Money::parseTyped($euros);
    }

    private function resetCloseState(): void
    {
        $this->countSubmitted = false;
        $this->countInput = '';
        $this->counted = null;
        $this->closeNote = '';
        $this->expected = null;
        $this->variance = null;
        $this->reweighing = false;
        $this->reweighDone = false;
        $this->reweighCounts = [];
        $this->reweighNotCounted = []; // 360 — a cancelled count (e.g. from the reason box) starts over, marks included
        $this->reweighResult = null;
        $this->reweighReason = null;
        $this->reweighAsking = false;
        $this->clockOutOffer = false;
        $this->clockedOutAt = null;
        $this->clockOutOtherId = null;
        $this->otherPin = '';
        $this->otherFeedback = null;
    }

    /** Whether this operator may bank cash (cash.bank) — the view hides the BANK option otherwise (prompt 81). */
    public function canBankCash(): bool
    {
        return $this->userCan('cash.bank');
    }

    /**
     * The slot the flash actually renders in this cycle (prompt 279): its own card when that card is on screen,
     * else the top. The fallback is what keeps a whole-screen outcome honest — "Caja cerrada." replaces the count
     * form with the arqueo, the recount's success replaces the recount form, a handover to someone without
     * `till.open` removes the handover card — and none of them can end up rendered nowhere.
     */
    private function flashSlot(?TillSessionModel $session): ?string
    {
        if ($this->flashAt === null || $this->countSubmitted || $session === null) {
            return null;
        }

        // The same branches the view takes: recount, then blind count, then the open-session screen.
        $onScreen = match (true) {
            $this->reweighing => ['reweigh'],
            $this->closing => ['count'],
            default => array_merge(
                $this->handoverOpen ? [] : ['movement', ...($this->userCan('expenses.record') ? ['expense'] : [])],
                $this->userCan('till.open') ? ['handover'] : [],
            ),
        };

        return in_array($this->flashAt, $onScreen, true) ? $this->flashAt : null;
    }

    private function flash(string $message, string $type): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
        $this->flashAt = $this->feedbackIn;
        $this->flashSeq++;
    }
}
