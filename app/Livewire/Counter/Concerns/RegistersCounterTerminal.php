<?php

namespace App\Livewire\Counter\Concerns;

use App\Actions\Counter\RegisterCounterTerminal;
use App\Actions\Counter\RevokeCounterTerminal;
use App\Actions\UnlockOperator;
use App\Models\Location;
use App\Models\User;
use App\Support\CounterOperator;
use App\Support\CounterTerminals;
use App\Support\LocationSwitcher;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Livewire\Attributes\On;

/**
 * Prompt 289 — "Registrar este dispositivo como mostrador" / "Olvidar este dispositivo", from the counter's top bar.
 *
 * `terminals.manage` (a manager at their own sedes), confirmed by the operator's OWN PIN again — the same pad rule and
 * UnlockOperator throttle as "Fichar salida". Registering issues the cookie ({@see CounterTerminals}); forgetting revokes
 * the terminal and clears it. Part of every counter screen through IdentifiesOperator.
 */
trait RegistersCounterTerminal
{
    /** 'register' | 'forget' | null — the dialog on screen. */
    public ?string $terminalDialog = null;

    public string $terminalName = '';

    public ?string $terminalLocationId = null;

    public string $terminalPin = '';

    public ?string $terminalFeedback = null;

    #[On('counter-terminal')]
    public function beginRegisterTerminal(): void
    {
        $operator = CounterOperator::current();

        if ($operator === null || ! $operator->can('terminals.manage')) {
            return;
        }

        $this->terminalFeedback = null;
        $this->terminalPin = '';

        if (CounterTerminals::current() !== null) {
            $this->terminalDialog = 'forget';

            return;
        }

        $this->terminalDialog = 'register';
        $this->terminalName = '';
        $this->terminalLocationId = $this->locationId;
    }

    public function cancelTerminal(): void
    {
        $this->reset(['terminalDialog', 'terminalName', 'terminalPin', 'terminalFeedback']);
    }

    /**
     * The sedes this operator may register the tablet at (their own; an owner's every sede; never the store).
     *
     * @return array<string, string>
     */
    public function terminalSedeOptions(): array
    {
        $operator = CounterOperator::current();

        return $operator !== null ? app(LocationSwitcher::class)->available($operator)->pluck('name', 'id')->all() : [];
    }

    public function confirmRegisterTerminal(): void
    {
        $operator = $this->terminalConfirmedOperator();
        $location = $this->terminalLocationId !== null ? Location::query()->withoutGlobalScopes()->find($this->terminalLocationId) : null;

        if ($operator === null) {
            return;
        }
        if ($location === null) {
            $this->terminalFeedback = __('Elige la sede de este mostrador.');

            return;
        }

        try {
            ['terminal' => $terminal, 'token' => $token] = (new RegisterCounterTerminal)->handle($operator, $location, $this->terminalName);
        } catch (AuthorizationException|InvalidArgumentException $e) {
            $this->terminalFeedback = $e->getMessage();

            return;
        }

        CounterTerminals::issue($terminal, $token);
        $this->cancelTerminal();
        $this->flash(__('Este dispositivo es ahora un mostrador: :name · :sede.', ['name' => $terminal->name, 'sede' => $location->name]), 'success');
    }

    public function confirmForgetTerminal(): void
    {
        $operator = $this->terminalConfirmedOperator();
        $terminal = CounterTerminals::current();

        if ($operator === null || $terminal === null) {
            return;
        }

        try {
            (new RevokeCounterTerminal)->handle($terminal, $operator);
        } catch (AuthorizationException $e) {
            $this->terminalFeedback = $e->getMessage();

            return;
        }

        CounterTerminals::forget();
        $this->cancelTerminal();
        $this->flash(__('Este dispositivo ya no es un mostrador.'), 'success');
    }

    /** The operator, when the PIN just typed is THEIRS (same throttle as every PIN pad); feedback otherwise. */
    private function terminalConfirmedOperator(): ?User
    {
        $operator = CounterOperator::current();
        $location = $this->resolveLocation();
        $pin = trim($this->terminalPin);
        $this->terminalPin = '';

        if ($operator === null || $location === null || ! $operator->can('terminals.manage')) {
            return null;
        }

        $matched = $pin === '' ? null : (new UnlockOperator)->handle($location, $pin, $this->operatorThrottleKey());

        if ($matched === null || ! $matched->is($operator)) {
            $this->terminalFeedback = $matched === null ? $this->pinFailureMessage() : __('Confirma con tu propio PIN.');

            return null;
        }

        return $operator;
    }
}
