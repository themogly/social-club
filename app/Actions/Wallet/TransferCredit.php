<?php

namespace App\Actions\Wallet;

use App\Actions\RecordAuditLog;
use App\Enums\WalletTransactionType;
use App\Models\Location;
use App\Models\Member;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Move member credit between two locations as a recorded, paired transfer
 * (TRANSFER_OUT at the source, TRANSFER_IN at the destination, linked by
 * transfer_pair_id) — the ring-fencing model's cross-location settlement.
 * Both halves are reported at their location.
 *
 * @return array{0: WalletTransaction, 1: WalletTransaction}
 */
class TransferCredit
{
    /**
     * @return array{0: WalletTransaction, 1: WalletTransaction}
     */
    public function handle(Member $member, Location $from, Location $to, int $amountCents, ?string $reason = null): array
    {
        return DB::transaction(function () use ($member, $from, $to, $amountCents, $reason): array {
            $recorder = new RecordWalletTransaction;

            $out = $recorder->handle($member, $from, -$amountCents, WalletTransactionType::TRANSFER_OUT, [
                'reason' => $reason, 'allow_debt' => true,
            ]);

            $in = $recorder->handle($member, $to, $amountCents, WalletTransactionType::TRANSFER_IN, [
                'reason' => $reason, 'allow_debt' => true, 'transfer_pair_id' => $out->id,
            ]);

            $out->update(['transfer_pair_id' => $in->id]);

            // Audit the cross-location move (prompt 48 placement — inside the same transaction). Covers
            // both the nightly auto-settlement sweep and a manual transfer; the reason distinguishes them.
            (new RecordAuditLog)->handle('wallet.transferred', $member, null, [
                'from_location_id' => $from->id,
                'to_location_id' => $to->id,
                'amount_cents' => $amountCents,
                'reason' => $reason,
            ]);

            return [$out, $in];
        });
    }
}
