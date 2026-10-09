<?php

namespace App\Actions\Till;

use App\Actions\RecordAuditLog;
use App\Enums\CashMovementType;
use App\Enums\CashPot;
use App\Enums\TillSessionStatus;
use App\Exceptions\TillAlreadyOpenException;
use App\Models\Location;
use App\Models\TillSession;
use App\Models\User;
use App\Support\CashBoxes;
use App\Support\CounterOperator;
use App\Support\TerminalName;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Open a till session with a float. One open session per terminal per location. */
class OpenTill
{
    /**
     * @param  array{operator_id?: ?string, notes?: ?string}  $options
     */
    public function handle(Location $location, string $terminal, int $floatCents, array $options = []): TillSession
    {
        // The grow / central store has no counter (prompt 277): no till, so nothing can be dispensed or sold there.
        if ($location->isStore()) {
            throw new RuntimeException(__('El almacén no tiene caja: solo guarda stock para las sedes.'));
        }

        // Normalise so "POS 1"/"POS-1"/"pos-1" are one terminal, and register it on the location (prompt 84).
        $terminal = TerminalName::clean($terminal);
        $key = TerminalName::key($terminal);

        return DB::transaction(function () use ($location, $terminal, $key, $floatCents, $options): TillSession {
            // Match by KEY, not the raw string, so a spelling variant cannot open a SECOND till.
            $open = TillSession::query()->withoutGlobalScopes()
                ->where('location_id', $location->id)
                ->where('status', TillSessionStatus::OPEN->value)->lockForUpdate()->get()
                ->first(fn (TillSession $s): bool => TerminalName::key((string) $s->terminal) === $key);

            if ($open !== null) {
                throw new TillAlreadyOpenException("Terminal {$terminal} already has an open till session.");
            }

            // Register the terminal on the location's configured list (idempotent by key).
            $located = Location::withoutGlobalScopes()->lockForUpdate()->findOrFail($location->id);
            $located->update(['terminals' => TerminalName::register($located->terminalNames(), $terminal)]);

            // Prompt 373 — the sede's own boxes (edibles, bar, fees), snapshotted onto the session so its arithmetic never
            // changes under it. The float is the till's; a box opens with what it held at this terminal's LAST close — the
            // count if counted, the expected if not — and only if it was a box at that close (a box newly separate opens at
            // 0). A box that held money and is now in the till is MERGED into it below: never in two places, never in none.
            $ownBoxes = CashBoxes::ownBoxesFor((string) $location->getKey());
            $held = self::heldAtLastClose($location, $key);
            $opening = fn (CashPot $pot): int => in_array($pot->value, $ownBoxes, true) ? ($held[$pot->value] ?? 0) : 0;

            $session = TillSession::create([
                'organisation_id' => $location->organisation_id,
                'location_id' => $location->id,
                'terminal' => $terminal,
                'opened_by' => $options['operator_id'] ?? CounterOperator::id() ?? Auth::id(),
                'opened_at' => now(),
                'float_cents' => $floatCents,
                'status' => TillSessionStatus::OPEN,
                'notes' => $options['notes'] ?? null,
                'separate_pots' => $ownBoxes !== [], // 349's column, kept for history; nothing reads it now
                'own_boxes' => $ownBoxes,
                'bar_opening_cents' => $opening(CashPot::BAR),
                'fees_opening_cents' => $opening(CashPot::FEES),
                'edibles_opening_cents' => $opening(CashPot::EDIBLES),
            ]);

            // Prompt 186 — the first shift, opened with the session. A single-operator day is then ONE shift
            // and behaves exactly as it did: the shift is the attributable unit, and a day with one operator
            // has one of them. Nothing about the session, the arqueo or any report that reconciles against
            // it changes.
            $operator = User::query()->find($session->opened_by);

            if ($operator !== null) {
                (new StartTillShift)->handle($session, $operator);
            }

            // Prompt 373 — a box that was separate at the last close, held money, and is in the till now: its money joins
            // the till as an automatic entry (audited), and the open screen tells staff to empty the box into the drawer.
            foreach ($held as $pot => $cents) {
                if ($cents > 0 && ! in_array($pot, $ownBoxes, true)) {
                    $box = CashPot::from($pot);
                    (new RecordCashMovement)->handle($session, CashMovementType::IN, $cents, [
                        'pot' => CashPot::DISPENSARY,
                        'reason' => $box->mergedNote(),
                        'operator_id' => $session->opened_by,
                    ]);
                    (new RecordAuditLog)->handle('till.box_merged', $session, null, ['pot' => $pot, 'amount_cents' => $cents, 'location_id' => $location->id]);
                }
            }

            return $session;
        });
    }

    /**
     * What each box held at this terminal's LAST close, whatever its settings (prompt 373 — 349 read the last close that had
     * pots, however long ago, and brought an old balance back): the count, or the expected if it went uncounted — and only
     * for a pot that WAS a box at that close. A pot that was in the till then holds nothing of its own.
     *
     * @return array<string, int> pot => cents
     */
    public static function heldAtLastClose(Location $location, string $terminalKey): array
    {
        $last = self::lastCloseAt($location, $terminalKey);

        $held = [];
        foreach ($last?->ownBoxes() ?? [] as $pot) {
            $held[$pot->value] = (int) ($last->getRawOriginal($pot->column().'_counted_cents') ?? $last->getRawOriginal($pot->column().'_expected_cents') ?? 0);
        }

        return $held;
    }

    /** Prompt 374 — the terminal's last close at the sede: what {@see heldAtLastClose()} merges from, and what *Sedes → Cajas* warns about. */
    public static function lastCloseAt(Location $location, string $terminalKey): ?TillSession
    {
        return TillSession::query()->withoutGlobalScopes()
            ->where('location_id', $location->id)->where('status', TillSessionStatus::CLOSED->value)
            ->orderByDesc('closed_at')->orderByDesc('id')->get()
            ->first(fn (TillSession $s): bool => TerminalName::key((string) $s->terminal) === $terminalKey);
    }
}
