<?php

namespace App\Livewire\Counter\Concerns;

use App\Support\Money;
use InvalidArgumentException;

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

    /** Quick-tender: set cashTendered to a preset (cents) or, when null, the exact cash owed. */
    public function quickCash(?int $cents = null): void
    {
        [$cashApplied] = $this->tenderSplit($this->tenderableTotalCents());

        $this->cashTendered = $this->eurosString($cents ?? $cashApplied);
    }

    /** Each screen provides its LIVE total (the basket total, already price-override-aware). */
    abstract protected function tenderableTotalCents(): int;

    /** Parse a euros string from the edge to integer cents, or null when blank/invalid. */
    protected function parseCents(string $euros): ?int
    {
        if (trim($euros) === '') {
            return null;
        }

        try {
            return Money::fromEuros($euros)->cents;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** Integer cents → a plain euros string ("12.50") for an input default — float-free. */
    protected function eurosString(int $cents): string
    {
        $cents = max(0, $cents);

        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
