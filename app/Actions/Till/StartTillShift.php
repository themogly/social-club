<?php

namespace App\Actions\Till;

use App\Enums\TillShiftStatus;
use App\Models\TillSession;
use App\Models\TillShift;
use App\Models\User;
use App\Support\TillSummary;

/**
 * The first shift of a session (prompt 186).
 *
 * Called by `OpenTill` so that a single-operator day is one shift and notices nothing — which is the case
 * that must not regress. Idempotent: a session that already has an open shift keeps it.
 */
class StartTillShift
{
    public function handle(TillSession $session, User $operator): TillShift
    {
        $existing = TillShift::query()->withoutGlobalScopes()
            ->where('till_session_id', $session->id)->open()->latest('opened_at')->first();

        if ($existing !== null) {
            return $existing;
        }

        return TillShift::create([
            'organisation_id' => $session->organisation_id,
            'till_session_id' => $session->id,
            'opened_by' => $operator->id,
            'opened_at' => $session->opened_at ?? now(),
            // The first shift is handed the float, and the ledger expects exactly the float at that instant.
            'opening_counted_cents' => (int) $session->getRawOriginal('float_cents'),
            'opening_expected_cents' => TillSummary::expectedCents($session),
            'status' => TillShiftStatus::OPEN,
        ]);
    }
}
