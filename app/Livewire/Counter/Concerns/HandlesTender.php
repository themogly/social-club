<?php

namespace App\Livewire\Counter\Concerns;

use App\Support\Money;
use App\Support\NumberFormat;

/**
 * The ONE tender model shared by both counter screens (prompt 74). Cash entered is what the member
 * HANDED OVER (`cashTendered`), never the amount to charge: the cash APPLIED to the transaction is always
 * the exact remainder after wallet (`tenderSplit` derives it), so the recorded split can never fail to
 * reconcile with the total. The screen shows the change; change is display-only and never reaches a ledger.
 *
 * Before this existed the bar POS modelled tender correctly (tendered + change) while the dispensary POS
 * treated its Cash field as the exact charge and refused any round note — two same-looking fields with
 * opposite semantics. Both screens now use this, so they cannot drift again.
 */
trait HandlesTender
{
    /** Wallet amount to apply, in euros (requires a socio). */
    public string $walletInput = '';

    /** Physical cash handed over, in euros — for the change-due display ONLY. Never recorded. */
    public string $cashTendered = '';

    /**
     * The tender split that is RECORDED: [cashApplied, walletApplied]. Wallet is capped at the total and
     * cash is the exact remainder, so the two ALWAYS sum to $total — the guard can never fail for a correct
     * entry. `cashTendered` (what the member handed) is deliberately not part of this.
     *
     * @return array{0: int, 1: int}
     */
    protected function tenderSplit(int $total): array
    {
        $wallet = min($this->requestedWalletCents(), max(0, $total));

        return [$total - $wallet, $wallet];
    }

    protected function requestedWalletCents(): int
    {
        return max(0, $this->parseCents($this->walletInput) ?? 0);
    }

    /** Change owed to the member: tendered − cash applied (0 unless over-tendered). NEVER stored/posted. */
    protected function changeDueCents(int $cashApplied): int
    {
        if (trim($this->cashTendered) === '') {
            return 0;
        }

        $tendered = $this->parseCents($this->cashTendered);

        if ($tendered === null || $tendered <= $cashApplied) {
            return 0;
        }

        return $tendered - $cashApplied;
    }

    /**
     * An UNDER-tender: physical cash was entered but is LESS than the cash owed. Over-tender produces
     * change; under-tender is an error and the commit must refuse it. A blank field means "exact" (no
     * error) — the applied cash is derived and correct either way.
     */
    protected function isUnderTendered(int $cashApplied): bool
    {
        if ($cashApplied <= 0 || trim($this->cashTendered) === '') {
            return false;
        }

        $tendered = $this->parseCents($this->cashTendered);

        return $tendered === null || $tendered < $cashApplied;
    }

    /**
     * Quick-tender (prompt 268): a note (€5/€10/€20) ADDS its value to what has been handed over — a member paying
     * with two twenties and a ten is three taps, €50,00 — while "Justo" (null) SETS the field to exactly the cash owed.
     * Each press used to OVERWRITE the field, so €20, €20, €10 read "10.00".
     */
    public function quickCash(?int $cents = null): void
    {
        [$cashApplied] = $this->tenderSplit($this->tenderableTotalCents());

        $this->cashTendered = $cents === null
            ? $this->eurosString($cashApplied)
            : $this->eurosString(($this->parseCents($this->cashTendered) ?? 0) + $cents);
    }

    /** "Borrar" — the undo for a mistaken note now that the notes add up (prompt 268). */
    public function clearTendered(): void
    {
        $this->cashTendered = '';
    }

    /**
     * What is still to collect when less cash has been handed over than is owed (prompt 268's "Falta") — the
     * explanation of the commit's "no cubre el total" refusal, which stays the real guard. 0 when blank or covered.
     */
    protected function shortfallCents(int $cashApplied): int
    {
        if ($cashApplied <= 0 || trim($this->cashTendered) === '') {
            return 0;
        }

        $tendered = $this->parseCents($this->cashTendered);

        return $tendered === null ? 0 : max(0, $cashApplied - $tendered);
    }

    /** Each screen provides its LIVE total (the basket total, already price-override-aware). */
    abstract protected function tenderableTotalCents(): int;

    /**
     * Parse a euros string from the edge to integer cents, or null when blank/invalid. Prompt 268: ONE unambiguous
     * reading — digits, optionally one separator (`,` or `.`) and one or two decimals ("50,00", "50.00", "50") — the
     * same rule as grams (257), so "1.000" is refused, never read as €1.
     */
    protected function parseCents(string $euros): ?int
    {
        return Money::parseTyped($euros); // the one typed-money rule (271)
    }

    /** Integer cents → a euros string for an input ("50.00") — a point, like every figure (316; 352 fixed the last comma). */
    protected function eurosString(int $cents): string
    {
        return NumberFormat::decimal(max(0, $cents) / 100, 2);
    }
}
