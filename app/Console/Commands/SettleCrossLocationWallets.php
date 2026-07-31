<?php

namespace App\Console\Commands;

use App\Actions\Wallet\AutoSettleDebt;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Support\ActiveScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Nightly cross-location wallet settlement (prompt 49). A member with credit at one UNFENCED location
 * and debt at another has the credit applied to clear that debt — recorded as a TRANSFER_OUT/IN pair
 * via TransferCredit, and audited. Ring-fenced locations (per-location `ring_fenced` setting) settle
 * only by explicit manual transfer and are skipped. Chosen over a writer-hook trigger: no re-entrancy,
 * lower risk, and it matches the app's established scheduled-command pattern.
 *
 * IDEMPOTENT: it reads LIVE balances (never a stored figure) and no-ops when there is nothing left to
 * settle, so a retry or a double-fire moves no extra money.
 */
class SettleCrossLocationWallets extends Command
{
    protected $signature = 'wallet:settle-cross-location';

    protected $description = 'Apply member credit at one location to clear their debt at another (unfenced locations only)';

    public function handle(): int
    {
        $scope = app(ActiveScope::class);
        $settler = new AutoSettleDebt;
        $membersSettled = 0;
        $totalCents = 0;

        foreach (Organisation::query()->get() as $organisation) {
            // Ring-fencing is a per-location Setting resolved THROUGH the org — without an active
            // organisation scope Settings::get falls back to the default and would treat a fenced
            // location as unfenced. Set it before any AutoSettleDebt call.
            $scope->setOrganisation($organisation->id);

            // A raw per-(member, location) balance aggregate — not entities — so query the builder
            // directly (stdClass rows) rather than hydrating models with a dynamic `bal` attribute.
            $balances = DB::table('wallet_transactions')
                ->where('organisation_id', $organisation->id)
                ->groupBy('member_id', 'location_id')
                ->selectRaw('member_id, location_id, SUM(amount_cents) as bal')
                ->havingRaw('SUM(amount_cents) <> 0')
                ->get();

            foreach ($balances->groupBy('member_id') as $memberId => $rows) {
                $creditRows = $rows->filter(fn (\stdClass $r): bool => (int) $r->bal > 0);
                $hasDebt = $rows->contains(fn (\stdClass $r): bool => (int) $r->bal < 0);

                // Only members with BOTH credit somewhere and debt elsewhere are candidates.
                if ($creditRows->isEmpty() || ! $hasDebt) {
                    continue;
                }

                $member = Member::withoutGlobalScopes()->find($memberId);
                if ($member === null) {
                    continue;
                }

                $memberCents = 0;
                foreach ($creditRows as $row) {
                    $creditLocation = Location::withoutGlobalScopes()->find($row->location_id);
                    if ($creditLocation === null) {
                        continue;
                    }
                    // AutoSettleDebt reads live balances and self-skips ring-fenced ends.
                    $memberCents += $settler->handle($member, $creditLocation);
                }

                if ($memberCents > 0) {
                    $membersSettled++;
                    $totalCents += $memberCents;
                }
            }
        }

        $this->info("Cross-location settlement: {$membersSettled} member(s), ".number_format($totalCents / 100, 2).' EUR moved.');

        return self::SUCCESS;
    }
}
