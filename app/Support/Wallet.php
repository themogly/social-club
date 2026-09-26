<?php

namespace App\Support;

use App\Models\Member;
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

    /**
     * A member's NET balance across every sede (prompt 259) — one sum over the same ledger, no schema change.
     * The wallet itself stays per-location (prompt 01; the ring-fence settlement is built on it).
     */
    public static function globalBalance(string $memberId): int
    {
        return (int) WalletTransaction::withoutGlobalScopes()->where('member_id', $memberId)->sum('amount_cents');
    }

    /**
     * What the member OWES in total: the sum of every sede's NEGATIVE balance (prompt 259). This, not the net
     * {@see globalBalance()}, is what the debt limit is measured against — a credit at a ring-fenced sede cannot
     * pay a debt elsewhere, so it must not buy extra tab either. Never looser than the net figure.
     */
    public static function totalDebtCents(string $memberId): int
    {
        return (int) WalletTransaction::withoutGlobalScopes()
            ->where('member_id', $memberId)
            ->selectRaw('location_id, SUM(amount_cents) as balance')
            ->groupBy('location_id')
            ->get()
            ->sum(fn (WalletTransaction $row): int => max(0, -(int) $row->getAttribute('balance')));
    }

    /**
     * How much MORE debt this member may take on at this sede right now (prompt 259) — the tab's headroom.
     *
     *   · the sede's `wallet_debt_allowed` is the master switch: off ⇒ 0 for everyone;
     *   · the member's `debt_limit_cents` is the grant, measured against their TOTAL debt across every sede —
     *     approved for €20 and owing €18 anywhere ⇒ €2, wherever they are;
     *   · a club-wide `wallet_debt_limit_cents` (when > 0) also caps this sede's own balance — the tightest
     *     ceiling wins.
     *
     * A limit lowered below existing debt yields 0 — it gates NEW debt, it never claws back what is owed.
     */
    public static function tabHeadroomCents(Member $member, string $locationId): int
    {
        if (! (bool) Settings::get('wallet_debt_allowed', false, $locationId)) {
            return 0;
        }

        $memberLimit = (int) ($member->debt_limit_cents ?? 0);
        if ($memberLimit <= 0) {
            return 0;
        }

        $room = $memberLimit - self::totalDebtCents($member->id);

        $clubLimit = (int) Settings::get('wallet_debt_limit_cents', 0, $locationId);
        if ($clubLimit > 0) {
            $room = min($room, $clubLimit - max(0, -self::balance($member->id, $locationId)));
        }

        return max(0, $room);
    }

    /** The largest DEBIT this sede's wallet may take for the member now: their credit here plus the tab's headroom. */
    public static function maxDebitCents(Member $member, string $locationId): int
    {
        return max(0, self::balance($member->id, $locationId)) + self::tabHeadroomCents($member, $locationId);
    }

    /** Total member credit held across an organisation — the wallet float (a liability, prompt 14). */
    public static function totalFloat(string $organisationId): int
    {
        return (int) WalletTransaction::withoutGlobalScopes()
            ->where('organisation_id', $organisationId)
            ->sum('amount_cents');
    }
}
