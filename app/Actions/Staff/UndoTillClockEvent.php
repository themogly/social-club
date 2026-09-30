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
 * *Deshacer* on the clock event the till wrote by itself: 312's clock-out at the close (OUT, TILL_CLOSE) and 338's clock-in
 * at the open (IN, TILL_OPEN). The same person, within
 * {@see self::WINDOW_SECONDS}, on the same counter session (a lock or someone else's PIN forgets the undo:
 * {@see CounterOperator::CLOCK_UNDO}). Never a delete: an ANNUL row pointing at the OUT (append-only, 281), so the period
 * is open again and the record shows both. Not {@see AnnulClockEvent}: that is a manager's correction of someone else's
 * hours; this is the person taking back their own automatic act, straight away.
 */
class UndoTillClockEvent
{
    public const WINDOW_SECONDS = 120;

    /** @throws DomainException when it can no longer be undone */
    public function handle(User $operator): StaffClockEvent
    {
        $eventId = session(CounterOperator::CLOCK_UNDO);
        $out = is_string($eventId) ? StaffClockEvent::query()->withoutGlobalScopes()->find($eventId) : null;

        $undoable = $out !== null && (($out->type === StaffClockType::OUT && $out->source === StaffClockSource::TILL_CLOSE)
            || ($out->type === StaffClockType::IN && $out->source === StaffClockSource::TILL_OPEN));

        if ($out === null
            || ! $undoable
            || CounterOperator::id() !== $operator->id
            || $out->user_id !== $operator->id
            || $out->recorded_at->lt(now()->subSeconds(self::WINDOW_SECONDS))
            || StaffClockEvent::query()->withoutGlobalScopes()->where('corrects_event_id', $out->id)->where('type', StaffClockType::ANNUL->value)->exists()) {
            session()->forget(CounterOperator::CLOCK_UNDO);

            throw new DomainException($out?->type === StaffClockType::IN
                ? __('Ya no se puede deshacer la entrada. Si hace falta, corrígela desde el registro de jornada.')
                : __('Ya no se puede deshacer la salida. Si hace falta, corrígela desde el registro de jornada.'));
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
                'source' => $out->source,
                'recorded_by' => $operator->id,
                'corrects_event_id' => $out->id,
                'reason' => $out->source === StaffClockSource::TILL_OPEN
                    ? __('Deshecho por la propia persona al abrir la caja')
                    : __('Deshecho por la propia persona al cerrar la caja'),
            ]);
            (new RecordAuditLog)->handle('staff.clock.undone', $annul, null, ['annulled_event_id' => $out->id, 'user_id' => $out->user_id]);
            session()->forget(CounterOperator::CLOCK_UNDO);

            return $annul;
        });
    }
}
