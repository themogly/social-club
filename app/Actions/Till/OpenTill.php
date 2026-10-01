<?php

namespace App\Actions\Till;

use App\Enums\TillSessionStatus;
use App\Exceptions\TillAlreadyOpenException;
use App\Models\Location;
use App\Models\TillSession;
use App\Models\User;
use App\Support\CounterOperator;
use App\Support\Settings;
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

            // Prompt 349 — *Botes de efectivo separados*: snapshotted onto the session (so its arithmetic never changes
            // under it). The float is the DISPENSARY pot's; the bar and fees pots open with what they held at this
            // terminal's last close — the count if they were counted, the expected if not (it carries until someone counts).
            $separate = (bool) Settings::get('separate_cash_pots', false, (string) $location->getKey());
            $carried = $separate ? self::carriedOpenings($location, $key) : ['bar' => 0, 'fees' => 0];

            $session = TillSession::create([
                'organisation_id' => $location->organisation_id,
                'location_id' => $location->id,
                'terminal' => $terminal,
                'opened_by' => $options['operator_id'] ?? CounterOperator::id() ?? Auth::id(),
                'opened_at' => now(),
                'float_cents' => $floatCents,
                'status' => TillSessionStatus::OPEN,
                'notes' => $options['notes'] ?? null,
                'separate_pots' => $separate,
                'bar_opening_cents' => $carried['bar'],
                'fees_opening_cents' => $carried['fees'],
            ]);

            // Prompt 186 — the first shift, opened with the session. A single-operator day is then ONE shift
            // and behaves exactly as it did: the shift is the attributable unit, and a day with one operator
            // has one of them. Nothing about the session, the arqueo or any report that reconciles against
            // it changes.
            $operator = User::query()->find($session->opened_by);

            if ($operator !== null) {
                (new StartTillShift)->handle($session, $operator);
            }

            return $session;
        });
    }

    /**
     * What the bar and fees pots held at this terminal's last close (prompt 349).
     *
     * @return array{bar: int, fees: int}
     */
    public static function carriedOpenings(Location $location, string $terminalKey): array
    {
        $last = TillSession::query()->withoutGlobalScopes()
            ->where('location_id', $location->id)->where('status', TillSessionStatus::CLOSED->value)->where('separate_pots', true)
            ->orderByDesc('closed_at')->orderByDesc('id')->get()
            ->first(fn (TillSession $s): bool => TerminalName::key((string) $s->terminal) === $terminalKey);

        if ($last === null) {
            return ['bar' => 0, 'fees' => 0];
        }

        $held = fn (string $pot): int => (int) ($last->getRawOriginal("{$pot}_counted_cents") ?? $last->getRawOriginal("{$pot}_expected_cents") ?? 0);

        return ['bar' => $held('bar'), 'fees' => $held('fees')];
    }
}
