<?php

namespace App\Actions\Till;

use App\Actions\RecordAuditLog;
use App\Enums\TillSessionStatus;
use App\Enums\TillShiftStatus;
use App\Exceptions\TillClosedException;
use App\Models\AuditLog;
use App\Models\StockTake;
use App\Models\TillSession;
use App\Models\TillShift;
use App\Models\User;
use App\Support\Settings;
use App\Support\TillSummary;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Close a till with a BLIND count: the operator submits the counted cash, and only
 * THEN is the expected figure (derived from the ledger) computed and the variance
 * revealed. Closing is `till.close` (manager+) and the session becomes immutable —
 * corrections are new linked entries.
 *
 * Prompt 366 — a difference NEVER blocks the close (Ben: "Just remove all these checks, as long as there's a log for the
 * owner to see and they can see an easy report"). The note is optional; a close beyond the tolerance is audited as
 * `till.closed_with_variance` — who, where, every pot's figures, the note (or none) and the evening flower count's
 * answer (or none) — and the tolerance in force is kept on the session for Informes → Cajas. What is still refused: no
 * `till.close`, a negative count, a till that is already closed.
 */
class CloseTill
{
    /**
     * @param  array<string, ?int>  $potCounts  prompt 349 — with pots on, the bar/fees counts keyed by pot value
     *                                          (BAR, FEES); null or missing = not counted tonight (it carries forward).
     */
    public function handle(TillSession $session, int $countedCents, User $closedBy, ?string $note = null, array $potCounts = []): TillSession
    {
        if (! $closedBy->can('till.close')) {
            throw new AuthorizationException('Closing a till requires the till.close permission.');
        }
        if ($countedCents < 0) {
            throw new InvalidArgumentException('A counted amount cannot be negative.');
        }
        $note = filled($note) ? trim($note) : null;

        // Lock the session for the read-then-write (prompt 77): compute the expected figure and mark the
        // session CLOSED in ONE transaction, holding the row lock, so a cash movement cannot land between
        // the two steps and be excluded from the immutable arqueo forever. RecordCashMovement contends on
        // the same lock, so a concurrent movement either commits BEFORE (counted) or is refused (closed).
        return DB::transaction(function () use ($session, $countedCents, $closedBy, $note, $potCounts): TillSession {
            $locked = TillSession::withoutGlobalScopes()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== TillSessionStatus::OPEN) {
                throw new TillClosedException('This till session is already closed.');
            }

            $breakdown = TillSummary::breakdown($locked);
            $expected = $breakdown['expected']; // the dispensary pot's when the session keeps pots, the drawer's otherwise
            $variance = $countedCents - $expected;
            $tolerance = (int) Settings::get('arqueo_variance_tolerance_cents', 500, $locked->location_id);

            // Prompt 349 — the bar and fees pots: counted tonight (against everything accumulated since their last count,
            // which is what their expected already holds through the carried opening), or not (the expected carries).
            $potColumns = [];
            $worst = abs($variance);
            if ($locked->ownBoxes() !== []) {
                foreach ($locked->ownBoxes() as $pot) { // prompt 373 — the session's own boxes (edibles too)
                    $potExpected = $breakdown['pots'][$pot->value]['expected'];
                    $potCounted = $potCounts[$pot->value] ?? null;
                    if ($potCounted !== null && $potCounted < 0) {
                        throw new InvalidArgumentException('A counted pot cannot be negative.');
                    }
                    $potColumns += [
                        $pot->column().'_expected_cents' => $potExpected,
                        $pot->column().'_counted_cents' => $potCounted,
                        $pot->column().'_variance_cents' => $potCounted !== null ? $potCounted - $potExpected : null,
                    ];
                    if ($potCounted !== null) {
                        $worst = max($worst, abs($potCounted - $potExpected));
                    }
                }
            }

            $locked->update([
                'counted_cents' => $countedCents,
                'expected_cents' => $expected,
                'variance_cents' => $variance,
                'variance_tolerance_cents' => $tolerance,
                'closed_by' => $closedBy->id,
                'closed_at' => now(),
                'status' => TillSessionStatus::CLOSED,
                'notes' => $note ?? $locked->notes,
            ] + $potColumns);

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
            ] + $potColumns);

            if ($worst > $tolerance) {
                (new RecordAuditLog)->handle('till.closed_with_variance', $locked, null, [
                    'location_id' => $locked->location_id,
                    'terminal' => $locked->terminal,
                    'closed_by' => $closedBy->id,
                    'expected_cents' => $expected,
                    'counted_cents' => $countedCents,
                    'variance_cents' => $variance,
                    'tolerance_cents' => $tolerance,
                ] + $potColumns + [
                    'note' => $note,
                ] + self::flowerCount($locked));
            }

            return $locked;
        });
    }

    /**
     * The evening flower count taken during this session at its sede (prompt 360's reweigh): staff's one answer when it was
     * off, and whether it went on with none («Seguir sin motivo», audited `stock.count_unexplained`).
     *
     * @return array{flower_reason: ?string, flower_unexplained: bool}
     */
    private static function flowerCount(TillSession $session): array
    {
        $takes = StockTake::query()->withoutGlobalScopes()
            ->where('location_id', $session->location_id)->where('kind', StockTake::KIND_TILL_RECOUNT)
            ->where('opened_at', '>=', $session->opened_at)->get(['id', 'reason']);

        return [
            'flower_reason' => $takes->pluck('reason')->filter()->first(),
            'flower_unexplained' => $takes->isNotEmpty() && AuditLog::query()->withoutGlobalScopes()->where('action', 'stock.count_unexplained')
                ->where('auditable_type', (new StockTake)->getMorphClass())->whereIn('auditable_id', $takes->pluck('id')->all())->exists(),
        ];
    }
}
