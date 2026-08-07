<?php

namespace App\Actions\Till;

use App\Actions\RecordAuditLog;
use App\Enums\TillSessionStatus;
use App\Enums\TillShiftStatus;
use App\Exceptions\TillClosedException;
use App\Models\TillSession;
use App\Models\TillShift;
use App\Models\User;
use App\Support\TillSummary;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Hand the drawer from one person to the next, WITHOUT closing the trading day (prompt 186).
 *
 * The owner's fork: the drawer belongs to the till, and a handover is counted. So the session — and the
 * arqueo, and every report that reconciles against it — continues as one; what changes is the shift.
 *
 * **The count is mandatory, and that follows from the reason the branch exists.** An uncounted handover
 * leaves the outgoing person's variance unknowable, which is precisely the problem being solved: a cash
 * variance is attributable to whoever held the drawer, and if nobody counted, a shortfall belongs to
 * nobody. There is no optional path here, so there is no "uncounted" state for a later reader to mistake
 * for a clean one.
 *
 * **The count is BLIND**, exactly like `CloseTill`. `expected_cents` and `variance_cents` are computed only
 * after `$countedCents` has been submitted, and nothing on the way in reveals what the drawer should hold.
 * This till closes blind twice over (cash and prompt 47's flower reweigh) and reusing the close-out's
 * components would make breaking that an easy accident.
 *
 * **Attribution is the assertion the whole thing exists for.** A shift's expected figure is what it was
 * HANDED plus what the ledger moved during it — never the session float — so the previous operator's
 * shortfall is not inherited by the next person. Both sides come from `TillSummary`, the one existing
 * source; nothing here recomputes a drawer figure.
 *
 * **Permission: `till.open`.** Not `till.close`: closing ends the trading day, produces the arqueo and is
 * manager-gated for that reason, and a handover explicitly does neither. Requiring a manager for every
 * shift change would reintroduce the problem — clubs run shift changes without one, so they would leave
 * the session open and share it, which is the behaviour this branch exists to remove. Both people
 * identify: the outgoing operator authorises the count, the incoming one takes the drawer.
 */
class HandOverTill
{
    public function handle(TillSession $session, int $countedCents, User $outgoing, User $incoming, ?string $note = null): TillShift
    {
        foreach ([$outgoing, $incoming] as $actor) {
            if (! $actor->can('till.open')) {
                throw new AuthorizationException('Handing over a till requires the till.open permission.');
            }
        }

        if ($countedCents < 0) {
            throw new RuntimeException('A counted drawer cannot be negative.');
        }

        // Same lock discipline as CloseTill (prompt 77): compute the expected figure and write both shifts
        // inside ONE transaction holding the session row, so a cash movement cannot land between the count
        // and the cut and be attributed to the wrong person.
        return DB::transaction(function () use ($session, $countedCents, $outgoing, $incoming, $note): TillShift {
            $locked = TillSession::withoutGlobalScopes()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== TillSessionStatus::OPEN) {
                throw new TillClosedException('This till session is closed — there is nothing to hand over.');
            }

            $current = TillShift::query()->withoutGlobalScopes()
                ->where('till_session_id', $locked->id)->open()->latest('opened_at')->first();

            if ($current === null) {
                throw new RuntimeException('No shift is open on this till — open one before handing it over.');
            }

            // BLIND: the count arrived before this line ran, and nothing above it revealed the expectation.
            $sessionExpectedNow = TillSummary::expectedCents($locked);
            $movedDuringShift = $sessionExpectedNow - (int) $current->getRawOriginal('opening_expected_cents');
            $expected = (int) $current->getRawOriginal('opening_counted_cents') + $movedDuringShift;

            $current->forceFill([
                'closed_by' => $outgoing->id,
                'closed_at' => now(),
                'counted_cents' => $countedCents,
                'expected_cents' => $expected,
                'variance_cents' => $countedCents - $expected,
                'notes' => $note,
                'status' => TillShiftStatus::CLOSED,
            ])->save();

            // The incoming shift starts from the COUNT, not from the expectation: whatever is physically in
            // the drawer is what the next person is accountable for.
            $next = TillShift::create([
                'organisation_id' => $locked->organisation_id,
                'till_session_id' => $locked->id,
                'opened_by' => $incoming->id,
                'opened_at' => now(),
                'opening_counted_cents' => $countedCents,
                'opening_expected_cents' => $sessionExpectedNow,
                'status' => TillShiftStatus::OPEN,
            ]);

            (new RecordAuditLog)->handle('till.handed_over', $locked, null, [
                'from' => $outgoing->id,
                'to' => $incoming->id,
                'counted_cents' => $countedCents,
                'variance_cents' => $countedCents - $expected,
            ]);

            return $next;
        });
    }
}
