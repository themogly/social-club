<?php

namespace App\Actions\Staff;

use App\Enums\StaffClockSource;
use App\Models\Location;
use App\Models\User;
use App\Support\WorkedHours;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/** Who may write which kind of clock event (prompt 281) — shared by ClockIn and ClockOut, so the two never disagree. */
final class ClockRules
{
    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     */
    public static function authorise(User $user, Location $location, User $recordedBy, StaffClockSource $source, ?string $reason): void
    {
        if ($location->isStore()) {
            throw new InvalidArgumentException(__('En el almacén no se ficha: la jornada es de una sede.'));
        }

        if ($source === StaffClockSource::MANAGER_CORRECTION) {
            if (! WorkedHours::canManageAt($recordedBy, $location)) {
                throw new AuthorizationException(__('No tienes permiso para corregir el registro de jornada de esta sede.'));
            }
            if (! WorkedHours::mayCorrect($recordedBy, $user)) {
                throw new AuthorizationException(__('Tus propias horas las corrige otra persona responsable.'));
            }
        } elseif (! $recordedBy->is($user)) {
            // PIN, TILL_CLOSE and SELF_DECLARED are the person's OWN act — personal, as the record requires.
            throw new AuthorizationException(__('Cada persona ficha su propia jornada.'));
        }

        if ($source->isDeclared() && trim((string) $reason) === '') {
            throw new InvalidArgumentException(__('Indica el motivo de la corrección.'));
        }
    }
}
