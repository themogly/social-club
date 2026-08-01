<?php

namespace App\Actions\Dispensing;

use App\Actions\RecordAuditLog;
use App\Actions\Stock\RecordStockMovement;
use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\DispensationStatus;
use App\Enums\StockMovementType;
use App\Enums\WalletTransactionType;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\Location;
use App\Models\Member;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Void a completed dispensation — never a silent edit or delete. Returns each
 * line's grams to its ORIGINATING batch (a positive stock ADJUSTMENT through the
 * single stock writer) and credits any wallet-paid contribution back (a REFUND),
 * then marks the row VOIDED with the reason and actor. Grams and cash release
 * automatically: the limit arithmetic (ResolveMemberLimits) and the till
 * expected-cash (TillSummary) both count COMPLETED rows only, so a VOIDED row
 * drops straight out of each — no counter to decrement. A CORRECTION is this void
 * plus a fresh dispensation linked back via reversal_of_id — recorded, not
 * rewritten. Voiding is `dispensation.void` (manager+), reasoned and audited.
 */
class VoidDispensation
{
    public function handle(Dispensation $dispensation, User $actor, string $reason): Dispensation
    {
        // Authorize through the POLICY, not the bare permission (prompt 75): DispensationPolicy::void binds
        // the actor to the row's OWN sede (via ::view), so a manager at sede A cannot void a sede-B row — a
        // bare `can('dispensation.void')` checked the permission but not the location, the exploited gap.
        if ($actor->cannot('void', $dispensation)) {
            throw new AuthorizationException('Voiding a dispensation requires dispensation.void at the row\'s location.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('A void requires a reason.');
        }

        return DB::transaction(function () use ($dispensation, $actor, $reason): Dispensation {
            // Lock the header so a void cannot race a second void or a correction.
            /** @var Dispensation $locked */
            $locked = Dispensation::withoutGlobalScopes()->whereKey($dispensation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== DispensationStatus::COMPLETED) {
                throw new RuntimeException('Only a completed dispensation can be voided.');
            }

            // Return each line's grams to the batch it came from (single stock writer,
            // which locks the batch row and appends the movement).
            foreach ($locked->lines()->get() as $line) {
                $batch = Batch::withoutGlobalScopes()->whereKey($line->batch_id)->firstOrFail();
                (new RecordStockMovement)->handle($batch, StockMovementType::ADJUSTMENT, $line->grams_cg->centigrams, [
                    'operator_id' => $actor->id,
                    'reason' => "Reversal of voided dispensation {$locked->id}",
                    'reference' => $locked->id,
                ]);
            }

            // Credit any wallet-paid contribution back to the member's wallet.
            if ($locked->wallet_cents->cents > 0) {
                $location = Location::withoutGlobalScopes()->findOrFail($locked->location_id);
                /** @var Member $member */
                $member = $locked->member()->withoutGlobalScopes()->firstOrFail();

                // A wallet credit is NOT a drawer movement, so it is deliberately not
                // attributed to a till session (that attribution is what pulls cash into
                // the arqueo). The cash side of the void needs no reversal here: the till
                // expected-cash counts COMPLETED rows only, so marking this VOIDED drops
                // its cash out automatically.
                (new RecordWalletTransaction)->handle($member, $location, $locked->wallet_cents->cents, WalletTransactionType::REFUND, [
                    'operator_id' => $actor->id,
                    'source' => $locked,
                    'reason' => "Reversal of voided dispensation {$locked->id}",
                    'allow_debt' => true,
                ]);
            }

            $locked->update([
                'status' => DispensationStatus::VOIDED,
                'void_reason' => $reason,
                'voided_by' => $actor->id,
                'voided_at' => now(),
            ]);

            (new RecordAuditLog)->handle('dispensation.voided', $locked, null, [
                'reason' => $reason,
                'voided_by' => $actor->id,
                'total_cents' => $locked->total_cents->cents,
                'wallet_reversed_cents' => $locked->wallet_cents->cents,
            ]);

            return $locked;
        });
    }
}
