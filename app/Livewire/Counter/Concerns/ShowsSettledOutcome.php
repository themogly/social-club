<?php

namespace App\Livewire\Counter\Concerns;

use App\Support\SettledOutcome;

/**
 * The last commit's outcome, held just long enough to be read (prompt 202).
 *
 * A confirmation that says only *"Pedido registrado."* tells the operator nothing the emptied basket had not
 * already told them, while the figure they are actually waiting on — the **change due** — is destroyed by the
 * cart reset a millisecond earlier. This holds the settled figures (see {@see SettledOutcome})
 * so the confirmation can carry them.
 *
 * **It is deliberately short-lived.** Money on a counter screen is only useful while it is the current
 * transaction; a stale *"Cambio €5,40"* is worse than nothing, because the next operator will act on it. So
 * it clears on the next basket action, and on every transition that means the person in front of the screen
 * may have changed — the lock, an operator switch, a handover. A sede switch is a full page load and clears
 * it by construction.
 */
trait ShowsSettledOutcome
{
    /**
     * The last commit's figures, or [] when there is nothing to show.
     *
     * A plain array, not a value object: it survives between Livewire requests without needing `Wireable`.
     *
     * @var array<string, mixed>
     */
    public array $settled = [];

    /**
     * Announce a settled transaction: ONE flash, carrying its figures.
     *
     * Deliberately the only way an outcome is ever set. Every other `flash()` clears it (see each screen's
     * `flash()`), so a stale figure cannot end up sitting under an unrelated message such as *"No hay stock"*.
     *
     * @param  array<string, mixed>  $outcome  from {@see SettledOutcome}
     */
    protected function flashSettled(array $outcome, string $message): void
    {
        $this->flash($message, 'success');
        $this->settled = $outcome;
    }

    /**
     * The operator has started the next transaction — the previous one's confirmation goes with them.
     *
     * Both fields, because the outcome renders INSIDE the flash: leaving the message would leave a figure
     * attached to it.
     */
    protected function dismissOutcome(): void
    {
        $this->flashMessage = null;
        $this->settled = [];
    }

    /** Forget the last outcome — the transaction it described is over. */
    public function clearSettledOutcome(): void
    {
        $this->settled = [];
    }
}
