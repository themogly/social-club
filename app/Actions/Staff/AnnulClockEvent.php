<?php

namespace App\Actions\Staff;

use App\Actions\RecordAuditLog;
use App\Enums\StaffClockSource;
use App\Enums\StaffClockType;
use App\Models\StaffClockEvent;
use App\Models\User;
use App\Support\WorkedHours;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * Annul a wrong clock event (prompt 281) — never by editing or deleting it: a NEW `ANNUL` row points at it, with who and
 * why. The report shows the annulled event struck through, never hidden. `staff.hours.manage` at the sede, and a reason.
 */
class AnnulClockEvent
{
    /**
     * @throws AuthorizationException
     * @throws DomainException
     * @throws InvalidArgumentException
     */
    public function handle(StaffClockEvent $event, User $actor, string $reason): StaffClockEvent
    {
        if ($event->location === null || ! WorkedHours::canManageAt($actor, $event->location)) {
            throw new AuthorizationException(__('No tienes permiso para corregir el registro de jornada de esta sede.'));
        }
        if (! WorkedHours::mayCorrect($actor, User::withTrashed()->findOrFail($event->user_id))) {
            throw new AuthorizationException(__('Tus propias horas las corrige otra persona responsable.'));
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException(__('Indica el motivo de la corrección.'));
        }
        if ($event->type === StaffClockType::ANNUL
            || StaffClockEvent::query()->withoutGlobalScopes()->where('corrects_event_id', $event->id)->where('type', StaffClockType::ANNUL->value)->exists()) {
            throw new DomainException(__('Este fichaje ya está anulado.'));
        }

        $annul = StaffClockEvent::create([
            'organisation_id' => $event->organisation_id,
            'user_id' => $event->user_id,
            'location_id' => $event->location_id,
            'type' => StaffClockType::ANNUL,
            'occurred_at' => now(),
            'recorded_at' => now(),
            'business_date' => $event->business_date->toDateString(),
            'source' => StaffClockSource::MANAGER_CORRECTION,
            'recorded_by' => $actor->id,
            'corrects_event_id' => $event->id,
            'reason' => trim($reason),
        ]);

        (new RecordAuditLog)->handle('staff.clock.annulled', $annul, null, ['annulled_event_id' => $event->id, 'user_id' => $event->user_id]);

        return $annul;
    }
}
