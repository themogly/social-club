<?php

namespace App\Actions\Staff;

use App\Actions\RecordAuditLog;
use App\Enums\StaffClockSource;
use App\Enums\StaffClockType;
use App\Models\StaffClockEvent;
use App\Models\User;
use App\Support\CounterOperator;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Prompt 312 — *Deshacer* on the clock-out that closing the till wrote. The same person, within
 * {@see self::WINDOW_SECONDS}, on the same counter session (a lock or someone else's PIN forgets the undo:
 * {@see CounterOperator::CLOCK_UNDO}). Never a delete: an ANNUL row pointing at the OUT (append-only, 281), so the period
 * is open again and the record shows both. Not {@see AnnulClockEvent}: that is a manager's correction of someone else's
 * hours; this is the person taking back their own automatic act, straight away.
 */
class UndoTillCloseClockOut
{
    public const WINDOW_SECONDS = 120;

    /** @throws DomainException when it can no longer be undone */
    public function handle(User $operator): StaffClockEvent
    {
        $eventId = session(CounterOperator::CLOCK_UNDO);
        $out = is_string($eventId) ? StaffClockEvent::query()->withoutGlobalScopes()->find($eventId) : null;

        if ($out === null
            || CounterOperator::id() !== $operator->id
            || $out->user_id !== $operator->id
            || $out->type !== StaffClockType::OUT
            || $out->source !== StaffClockSource::TILL_CLOSE
            || $out->recorded_at->lt(now()->subSeconds(self::WINDOW_SECONDS))
            || StaffClockEvent::query()->withoutGlobalScopes()->where('corrects_event_id', $out->id)->where('type', StaffClockType::ANNUL->value)->exists()) {
            session()->forget(CounterOperator::CLOCK_UNDO);

            throw new DomainException(__('Ya no se puede deshacer la salida. Si hace falta, corrígela desde el registro de jornada.'));
        }

        return DB::transaction(function () use ($out, $operator): StaffClockEvent {
            $annul = StaffClockEvent::create([
                'organisation_id' => $out->organisation_id,
                'user_id' => $out->user_id,
                'location_id' => $out->location_id,
                'type' => StaffClockType::ANNUL,
                'occurred_at' => now(),
                'recorded_at' => now(),
                'business_date' => $out->business_date->toDateString(),
                'source' => StaffClockSource::TILL_CLOSE,
                'recorded_by' => $operator->id,
                'corrects_event_id' => $out->id,
                'reason' => __('Deshecho por la propia persona al cerrar la caja'),
            ]);
            (new RecordAuditLog)->handle('staff.clock.undone', $annul, null, ['annulled_event_id' => $out->id, 'user_id' => $out->user_id]);
            session()->forget(CounterOperator::CLOCK_UNDO);

            return $annul;
        });
    }
}
