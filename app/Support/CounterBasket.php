<?php

namespace App\Support;

use Illuminate\Support\Facades\Session;

/**
 * A basket in progress, held across navigation (prompt 205).
 *
 * **What was there before was a lie.** Both POS screens wrote the basket to `localStorage` on every change:
 *
 *     this.$watch(() => JSON.stringify($wire.basket), v => localStorage.setItem('bar.basket', v))
 *
 * and **nothing in the entire `resources/` tree ever called `getItem`.** It read like a safety net for two
 * years and caught nothing. 205 made that matter: with the destinations gone from the top bar, every screen
 * change goes through the hub, so a basket that does not survive navigation makes hub-only navigation
 * unusable — the operator would be asked *"are you sure you want to lose this"* on the most common action in
 * the product.
 *
 * **Held server-side, not in localStorage.** The basket is already server state — a Livewire property that
 * the commit Actions read. Restoring it from the client would mean a second source of truth for the thing
 * the compliance boundary acts on, plus a reconciliation rule for whose copy wins. The session has neither
 * problem and survives a full page load, which is what `wire:navigate.ignore` does.
 *
 * **Scoped to (screen · sede · operator), and that scoping is the safety property.** A basket must never
 * reappear under a different person or at a different sede: the same reasoning as prompt 202's settled
 * outcome, one step more serious, because this one can be committed. Locking does NOT clear it — prompt 198
 * is explicit that work survives a lock — but the operator key means a DIFFERENT operator unlocking never
 * sees it.
 */
class CounterBasket
{
    /** Keep a stashed basket, and drop it when it is this old. A shift's worth, not a day's. */
    private const TTL_MINUTES = 240;

    /**
     * Stash this screen's basket for the current operator at the current sede.
     *
     * @param  array<int, mixed>  $basket
     */
    public static function put(string $screen, ?string $locationId, array $basket): void
    {
        $key = self::key($screen, $locationId);

        if ($key === null) {
            return;
        }

        if ($basket === []) {
            Session::forget($key);

            return;
        }

        Session::put($key, ['at' => now()->timestamp, 'basket' => $basket]);
    }

    /**
     * The stashed basket, or [] when there is none (or it has gone stale).
     *
     * @return array<int, mixed>
     */
    public static function get(string $screen, ?string $locationId): array
    {
        $key = self::key($screen, $locationId);

        if ($key === null) {
            return [];
        }

        $stashed = Session::get($key);

        if (! is_array($stashed) || ! is_array($stashed['basket'] ?? null)) {
            return [];
        }

        // Stale baskets are dropped rather than restored: a line priced this morning is not a line to commit
        // this evening, and every figure is re-resolved at commit anyway (ResolvePrice, SelectBatch).
        if (now()->timestamp - (int) ($stashed['at'] ?? 0) > self::TTL_MINUTES * 60) {
            Session::forget($key);

            return [];
        }

        return array_values($stashed['basket']);
    }

    public static function forget(string $screen, ?string $locationId): void
    {
        $key = self::key($screen, $locationId);

        if ($key !== null) {
            Session::forget($key);
        }
    }

    /**
     * `counter.basket.<screen>.<sede>.<operator>` — null when there is no sede or no identified operator,
     * in which case there is nothing to stash against and nothing to restore.
     */
    private static function key(string $screen, ?string $locationId): ?string
    {
        $operator = CounterOperator::id();

        if ($locationId === null || $operator === null) {
            return null;
        }

        return 'counter.basket.'.$screen.'.'.$locationId.'.'.$operator;
    }
}
