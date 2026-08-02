<?php

namespace App\Livewire\Counter\Concerns;

use App\Actions\UnlockOperator;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Livewire\Attributes\On;

/**
 * PIN operator identification, shared by every counter screen (prompt 02/26). A till
 * runs under ONE device login but MANY staff; each identifies with a personal PIN so
 * the operator recorded on a transaction is the PERSON who did it, never the device
 * session user. The backend (hashed pin, {@see UnlockOperator} with rate limiting,
 * {@see CounterOperator} session store) already existed and is reused verbatim — this
 * trait is the missing wiring + UI surface.
 *
 * The composing component must expose `resolveLocation(): ?\App\Models\Location` and
 * `flash(string, string): void` — every counter component already does.
 */
trait IdentifiesOperator
{
    /** Bound to the PIN pad. Never persisted, never logged, cleared after every attempt. */
    public string $operatorPin = '';

    public bool $operatorPanelOpen = false;

    /** Last unlock feedback for the panel (wrong PIN / locked out); never the PIN itself. */
    public ?string $operatorFeedback = null;

    public function currentOperatorName(): ?string
    {
        return CounterOperator::current()?->name;
    }

    public function hasOperator(): bool
    {
        return CounterOperator::id() !== null;
    }

    public function operatorLockedOut(): bool
    {
        return (new UnlockOperator)->isLockedOut($this->operatorThrottleKey());
    }

    /** Seconds until the pad accepts a PIN again (0 when not locked) — the pad shows the countdown. */
    public function operatorLockoutSeconds(): int
    {
        return (new UnlockOperator)->lockoutSecondsRemaining($this->operatorThrottleKey());
    }

    /**
     * Lock the counter (prompt 120): the idle timer or the manual "lock now" button dispatches `counter-lock`,
     * and locking simply signs the operator OUT. That reuses the existing gate — every commit already calls
     * requireOperator() and now finds no operator, so writes are refused SERVER-SIDE, not just hidden behind the
     * overlay. Basket/session state is untouched, so unlocking (any valid PIN) resumes exactly where it left off.
     */
    #[On('counter-lock')]
    public function lockCounter(): void
    {
        CounterOperator::clear();
    }

    public function openOperatorPanel(): void
    {
        $this->operatorPanelOpen = true;
        $this->operatorPin = '';
        $this->operatorFeedback = null;
    }

    public function closeOperatorPanel(): void
    {
        $this->operatorPanelOpen = false;
        $this->operatorPin = '';
    }

    /** Sign the current operator out and reopen the pad for the next person. */
    public function switchOperator(): void
    {
        CounterOperator::clear();
        $this->openOperatorPanel();
    }

    /** Verify the entered PIN against the location's active staff and, on success, set the operator. */
    public function unlockOperator(): void
    {
        $location = $this->resolveLocation();

        if ($location === null) {
            $this->operatorFeedback = __('Sin sede activa.');

            return;
        }

        $pin = trim($this->operatorPin);
        $this->operatorPin = '';                     // never keep the PIN in component state

        if ($pin === '') {
            $this->operatorFeedback = __('Introduce tu PIN.');

            return;
        }

        $operator = (new UnlockOperator)->handle($location, $pin, $this->operatorThrottleKey());

        if ($operator === null) {
            $this->operatorFeedback = $this->operatorLockedOut()
                ? __('Demasiados intentos. Espera un momento antes de reintentar.')
                : __('PIN no reconocido.');

            return;
        }

        CounterOperator::set($operator);
        $this->operatorPanelOpen = false;
        $this->operatorFeedback = null;
        // Tell the idle-lock overlay (prompt 120) to lift — the same PIN pad both identifies a new operator and
        // unlocks an idle-locked screen, so a successful unlock always clears the overlay.
        $this->dispatch('counter-unlocked');
        $this->flash(__('Trabajando: :name', ['name' => $operator->name]), 'success');
    }

    /**
     * Guard a counter transaction: an operator must be PIN-identified first. Returns
     * false (and prompts to unlock) when none is — so the caller bails BEFORE writing,
     * never silently attributing the transaction to the device session user.
     */
    protected function requireOperator(): bool
    {
        if ($this->hasOperator()) {
            return true;
        }

        $this->operatorPanelOpen = true;
        $this->flash(__('Identifícate con tu PIN antes de continuar.'), 'error');

        return false;
    }

    /**
     * LOCATION-WIDE throttle bucket (prompt 120). Was per-device (…:sessionId), but a shared counter has many
     * devices and a browser session is trivial to rotate — so the count is keyed to the sede only, and every
     * device at that sede shares one escalating lockout. A wrong-sede/no-sede terminal buckets under 'none'.
     */
    private function operatorThrottleKey(): string
    {
        $locationId = app(ActiveScope::class)->locationId() ?? 'none';

        return 'counter-pin:'.$locationId;
    }
}
