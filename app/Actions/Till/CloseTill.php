<?php

namespace App\Actions\Till;

use App\Actions\RecordAuditLog;
use App\Enums\TillSessionStatus;
use App\Enums\TillShiftStatus;
use App\Exceptions\TillClosedException;
use App\Models\TillSession;
use App\Models\TillShift;
use App\Models\User;
use App\Support\Settings;
use App\Support\TillSummary;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Close a till with a BLIND count: the operator submits the counted cash, and only
 * THEN is the expected figure (derived from the ledger) computed and the variance
 * revealed. A variance beyond tolerance requires a note. Closing is `till.close`
 * (manager+) and the session becomes immutable — corrections are new linked entries.
 */
class CloseTill
{
    public function handle(TillSession $session, int $countedCents, User $closedBy, ?string $note = null): TillSession
    {
        if (! $closedBy->can('till.close')) {
            throw new AuthorizationException('Closing a till requires the till.close permission.');
        }

        // Lock the session for the read-then-write (prompt 77): compute the expected figure and mark the
        // session CLOSED in ONE transaction, holding the row lock, so a cash movement cannot land between
        // the two steps and be excluded from the immutable arqueo forever. RecordCashMovement contends on
        // the same lock, so a concurrent movement either commits BEFORE (counted) or is refused (closed).
        return DB::transaction(function () use ($session, $countedCents, $closedBy, $note): TillSession {
            $locked = TillSession::withoutGlobalScopes()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== TillSessionStatus::OPEN) {
                throw new TillClosedException('This till session is already closed.');
            }

            $expected = TillSummary::expectedCents($locked);
            $variance = $countedCents - $expected;
            $tolerance = (int) Settings::get('arqueo_variance_tolerance_cents', 500);

            if (abs($variance) > $tolerance && blank($note)) {
                throw new RuntimeException('A note is required when the variance exceeds the tolerance.');
            }

            $locked->update([
                'counted_cents' => $countedCents,
                'expected_cents' => $expected,
                'variance_cents' => $variance,
                'closed_by' => $closedBy->id,
                'closed_at' => now(),
                'status' => TillSessionStatus::CLOSED,
                'notes' => $note ?? $locked->notes,
            ]);

            // Prompt 186 — the final shift closes WITH the session, against the same count. The day's figures
            // then reconcile whether it held one shift or three: each shift's expected is what it was handed
            // plus what moved during it, so the shifts' variances sum to the session's. Nothing about the
            // arqueo itself changed — the count, the expected figure, the variance and the note are all
            // exactly as they were, which is why a single-operator club sees no difference.
            $shift = TillShift::query()->withoutGlobalScopes()
                ->where('till_session_id', $locked->id)->open()->latest('opened_at')->first();

            if ($shift !== null) {
                $shiftExpected = (int) $shift->getRawOriginal('opening_counted_cents')
                    + ($expected - (int) $shift->getRawOriginal('opening_expected_cents'));

                $shift->forceFill([
                    'closed_by' => $closedBy->id,
                    'closed_at' => now(),
                    'counted_cents' => $countedCents,
                    'expected_cents' => $shiftExpected,
                    'variance_cents' => $countedCents - $shiftExpected,
                    'notes' => $note,
                    'status' => TillShiftStatus::CLOSED,
                ])->save();
            }

            (new RecordAuditLog)->handle('till.closed', $locked, null, [
                'expected_cents' => $expected,
                'counted_cents' => $countedCents,
                'variance_cents' => $variance,
            ]);

            return $locked;
        });
    }
}
