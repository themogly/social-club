<?php

namespace App\Actions\Till;

use App\Enums\TillSessionStatus;
use App\Exceptions\TillAlreadyOpenException;
use App\Models\Location;
use App\Models\TillSession;
use App\Models\User;
use App\Support\CounterOperator;
use App\Support\TerminalName;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Open a till session with a float. One open session per terminal per location. */
class OpenTill
{
    /**
     * @param  array{operator_id?: ?string, notes?: ?string}  $options
     */
    public function handle(Location $location, string $terminal, int $floatCents, array $options = []): TillSession
    {
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

            $session = TillSession::create([
                'organisation_id' => $location->organisation_id,
                'location_id' => $location->id,
                'terminal' => $terminal,
                'opened_by' => $options['operator_id'] ?? CounterOperator::id() ?? Auth::id(),
                'opened_at' => now(),
                'float_cents' => $floatCents,
                'status' => TillSessionStatus::OPEN,
                'notes' => $options['notes'] ?? null,
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
}
