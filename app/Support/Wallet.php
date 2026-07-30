<?php

namespace App\Support;

use App\Models\WalletTransaction;

/**
 * The wallet balance is DERIVED from the append-only ledger, never stored/free-typed.
 * Positive = credit paid in; negative = debt. Per-location (prompt 01 checkpoint).
 */
class Wallet
{
    /** A member's balance at a location, in integer cents (summed from the ledger). */
    public static function balance(string $memberId, string $locationId): int
    {
        return (int) WalletTransaction::withoutGlobalScopes()
            ->where('member_id', $memberId)
            ->where('location_id', $locationId)
            ->sum('amount_cents');
    }

    /** Total member credit held across an organisation — the wallet float (a liability, prompt 14). */
    public static function totalFloat(string $organisationId): int
    {
        return (int) WalletTransaction::withoutGlobalScopes()
            ->where('organisation_id', $organisationId)
            ->sum('amount_cents');
    }
}
