<?php

namespace App\Livewire\Counter\Concerns;

use App\Support\CounterBasket;

/**
 * Keep the basket across navigation (prompt 205).
 *
 * 205 removed the destinations from the top bar, so every screen change now goes through the hub. A basket
 * that does not survive that makes hub-only navigation unusable: the operator would meet prompt 196's
 * unsaved-work confirm on the most common action in the product, and lose the basket if they said yes.
 *
 * Saving is a **lifecycle hook**, not a line in each mutation. The basket is written by a dozen methods on
 * each screen — `addArticle`, `addLine`, `stepUnits`, `removeLine`, the calculator pad — and a rule that has
 * to be remembered in twelve places will be forgotten in a thirteenth. `dehydrate` runs at the end of every
 * request, so whatever the basket IS when the response is built is what gets stashed, including `[]` after a
 * commit (which forgets it).
 *
 * Restoring is explicit and called at the end of `mount()`, because it needs the sede that `mount()`
 * resolves.
 *
 * The host declares `basketScreen()` — the key this screen stashes under — and has a `$basket` array.
 */
trait PersistsBasket
{
    /** `pos` or `bar`: the two screens with a basket. */
    abstract protected function basketScreen(): string;

    /** Livewire calls this at the end of every request for this trait. */
    public function dehydratePersistsBasket(): void
    {
        CounterBasket::put($this->basketScreen(), $this->locationId, $this->basket);
    }

    /**
     * Bring back a basket left on this screen — same operator, same sede, within the window.
     *
     * Deliberately only when the screen is starting empty: a fresh mount with lines already on it would
     * mean something else has restored them, and overwriting that is how two sources of truth start.
     */
    protected function restoreBasket(): void
    {
        if ($this->basket !== []) {
            return;
        }

        $this->basket = CounterBasket::get($this->basketScreen(), $this->locationId);
    }
}
