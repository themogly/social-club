<?php

namespace App\Livewire\Counter;

use App\Actions\Expenses\RecordTillExpense;
use App\Actions\Till\CloseTill;
use App\Actions\Till\OpenTill;
use App\Actions\Till\RecordCashMovement;
use App\Enums\CashMovementType;
use App\Enums\TillSessionStatus;
use App\Exceptions\TillAlreadyOpenException;
use App\Exceptions\TillClosedException;
use App\Models\ExpenseCategory;
use App\Models\Location;
use App\Models\TillSession as TillSessionModel;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Money;
use App\Support\TillSummary;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
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
    /** The active location id, resolved in mount(). */
    public ?string $locationId = null;

    /** Friendly state when the operator has no location at all (still a 200). */
    public bool $noLocation = false;

    /** The terminal this screen manages — the open form binds it; it then keys the session. */
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

        $terminal = trim($this->terminal);

        if ($terminal === '') {
            $this->flash(__('Indica el terminal.'), 'error');

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

    // --- Blind close (arqueo) --------------------------------------------------

    /** Enter the blind count: hide the live summary and clear any prior reveal. */
    public function startClose(): void
    {
        $this->closing = true;
        $this->resetCloseState();
    }

    public function cancelClose(): void
    {
        $this->closing = false;
        $this->resetCloseState();
    }

    public function submitCount(): void
    {
        $session = $this->resolveOpenSession();

        if ($session === null) {
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

        return view('livewire.counter.till-session', [
            'location' => $location,
            'session' => $session,
            'breakdown' => $breakdown,
            'expenseCategories' => $expenseCategories,
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
