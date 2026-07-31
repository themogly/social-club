<?php

namespace App\Livewire\Counter;

use App\Actions\Expenses\RecordTillExpense;
use App\Actions\Memberships\RecordFeePayment;
use App\Actions\Stock\CommitStockTake;
use App\Actions\Till\CloseTill;
use App\Actions\Till\OpenTill;
use App\Actions\Till\RecordCashMovement;
use App\Enums\BatchStatus;
use App\Enums\CashMovementType;
use App\Enums\FeePaymentMethod;
use App\Enums\MembershipStatus;
use App\Enums\StockTakeStatus;
use App\Enums\TillSessionStatus;
use App\Enums\UnitType;
use App\Exceptions\DebtLimitExceededException;
use App\Exceptions\TillAlreadyOpenException;
use App\Exceptions\TillClosedException;
use App\Livewire\Counter\Concerns\IdentifiesOperator;
use App\Models\Batch;
use App\Models\ExpenseCategory;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipFeePayment;
use App\Models\StockTake;
use App\Models\StockTakeLine;
use App\Models\TillSession as TillSessionModel;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Money;
use App\Support\TerminalName;
use App\Support\TillSummary;
use App\Support\Weight;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
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
    use IdentifiesOperator;

    /** The active location id, resolved in mount(). */
    public ?string $locationId = null;

    /** Friendly state when the operator has no location at all (still a 200). */
    public bool $noLocation = false;

    /** The terminal this screen manages — the open form binds it; it then keys the session. */
    public string $terminal = '';

    /** A brand-new terminal name typed at open (prompt 84) — used in preference to the picked one when filled. */
    public string $newTerminal = '';

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

    /** @var list<array{name: string, counted: string, variance: string, adjusted: bool}>|null Revealed after commit. */
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

    // --- Fee collection (Cobrar cuota) -----------------------------------------

    /** Org-wide member search to pick who's paying a membership fee. */
    public string $feeSearch = '';

    /** The held member id — their outstanding fee is resolved live, never stored. */
    public ?string $feeMemberId = null;

    /** The amount being collected, in euros (partial/instalment allowed). */
    public string $feeAmount = '';

    /** CASH (into the drawer) or WALLET (posts a FEE ledger movement). */
    public string $feeMethod = 'CASH';

    public function mount(): void
    {
        abort_unless($this->userCan('till.open') || $this->userCan('till.close'), 403);

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

        // Convenience: resume the single open session at this sede so returning to the
        // screen lands on its summary. Ambiguous (several terminals open) → operator picks.
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

    // --- Open ------------------------------------------------------------------

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

        // Prefer a newly-typed terminal over the picked one; OpenTill normalises + registers it (prompt 84).
        $terminal = TerminalName::clean($this->newTerminal !== '' ? $this->newTerminal : $this->terminal);

        if ($terminal === '') {
            $this->flash(__('Elige un terminal o escribe uno nuevo.'), 'error');

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
        $this->newTerminal = '';
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

        if ($type === null) {
            $this->flash(__('Tipo de movimiento no válido.'), 'error');

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

    public function selectFeeMember(string $memberId): void
    {
        $this->feeMemberId = $memberId;
        $this->feeSearch = '';
    }

    public function clearFeeMember(): void
    {
        $this->reset(['feeMemberId', 'feeSearch', 'feeAmount']);
        $this->feeMethod = 'CASH';
    }

    public function collectFee(): void
    {
        $session = $this->resolveOpenSession();

        // A CASH fee MUST attach to an open session (the till-reconciliation invariant); the fee
        // action lives on the till, so we require the open drawer for either method.
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
        $member = $this->feeMemberId !== null ? Member::query()->find($this->feeMemberId) : null;

        if ($member === null || $location === null) {
            $this->flash(__('Selecciona un socio.'), 'error');

            return;
        }

        // The SAME membership feesPaid() checks: the latest active one at this sede.
        $membership = $this->outstandingMembership($member, $location);

        if ($membership === null) {
            $this->flash(__('Este socio no tiene cuota pendiente en esta sede.'), 'error');

            return;
        }

        $owed = $this->owedCents($membership);
        $cents = $this->toCents($this->feeAmount);

        if ($cents === null || $cents <= 0) {
            $this->flash(__('El importe no es válido.'), 'error');

            return;
        }

        if ($cents > $owed) {
            $this->flash(__('El importe supera la cuota pendiente (:owed).', ['owed' => $this->money($owed)]), 'error');

            return;
        }

        $method = $this->feeMethod === 'WALLET' ? FeePaymentMethod::WALLET : FeePaymentMethod::CASH;

        try {
            (new RecordFeePayment)->handle($membership, $cents, $method, [
                'till_session_id' => $session->id,
                'operator_id' => CounterOperator::id() ?? $user->id,
            ]);
        } catch (DebtLimitExceededException $e) {
            $this->flash(__('El monedero no admite el cargo: :reason', ['reason' => $e->getMessage()]), 'error');

            return;
        }

        $remaining = $owed - $cents;
        $this->reset(['feeMemberId', 'feeSearch', 'feeAmount']);
        $this->feeMethod = 'CASH';

        $this->flash($remaining > 0
            ? __('Cuota parcial cobrada. Pendiente: :remaining', ['remaining' => $this->money($remaining)])
            : __('Cuota cobrada por completo.'), 'success');
    }

    /** The member's outstanding membership at this sede (latest active with a balance), or null. */
    private function outstandingMembership(Member $member, Location $location): ?Membership
    {
        $membership = $member->memberships()->withoutGlobalScopes()
            ->where('location_id', $location->id)
            ->where('status', MembershipStatus::ACTIVE->value)
            ->latest('id')->first();

        return ($membership !== null && $this->owedCents($membership) > 0) ? $membership : null;
    }

    private function owedCents(Membership $membership): int
    {
        $paid = (int) MembershipFeePayment::query()->where('membership_id', $membership->id)->sum('amount_cents');

        return max(0, $membership->fee_cents->cents - $paid);
    }

    /**
     * Org-wide socio search — the SAME by-name/member_no query the dispensary POS uses.
     *
     * @return Collection<int, Member>|null
     */
    private function feeSearchResults(): ?Collection
    {
        $term = trim($this->feeSearch);

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
            $raw = trim($this->reweighCounts[$batch->id] ?? '');
            if ($raw === '' || ! is_numeric($raw) || (float) $raw < 0) {
                $this->flash(__('Introduce el peso contado de cada lote de flor.'), 'error');

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
            $variance = $line?->variance_cg->centigrams ?? 0;
            $counted = $line?->counted_cg->centigrams ?? 0;

            return [
                'name' => trim($batch->genetic->name.' · '.$batch->batch_no),
                'counted' => Weight::fromCentigrams($counted)->formatted(),
                'variance' => Weight::fromCentigrams($variance)->formatted(),
                'adjusted' => $variance !== 0,
            ];
        })->all();

        $this->reweighDone = true;
        $this->reweighing = false;
        $this->reweighCounts = [];
        $this->flash(__('Recuento de flor registrado.'), 'success');
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
                'location' => $location,
                'session' => null,
                'breakdown' => null,
                'expenseCategories' => collect(),
            ]);
        }

        $session = $this->noLocation ? null : $this->resolveOpenSession();

        // While counting the drawer (closing, not yet submitted) NOTHING about the
        // expected figure is computed and NO breakdown reaches the view — a true blind
        // count. The breakdown (incl. expected) is only assembled for normal operation.
        $breakdown = ($session !== null && ! $this->closing)
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
