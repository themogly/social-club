<?php

namespace App\Actions\Staff;

use App\Actions\RecordAuditLog;
use App\Enums\StaffClockSource;
use App\Enums\StaffClockType;
use App\Models\Location;
use App\Models\StaffClockEvent;
use App\Models\User;
use App\Support\BusinessDay;
use App\Support\WorkedHours;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Start a working period (prompt 281, Ben's 280) — an IN on the append-only registro de jornada.
 *
 * Live (`PIN`) it is the person's own act: `$recordedBy` must be them. As a `MANAGER_CORRECTION` (a missing entrada
 * added in the panel) it needs `staff.hours.manage` at the sede and a reason. A person has at most ONE open period in the
 * whole organisation: clocking in while still clocked in elsewhere is refused, naming where.
 */
class ClockIn
{
    /**
     * @throws AuthorizationException
     * @throws DomainException
     * @throws InvalidArgumentException
     */
    public function handle(User $user, Location $location, User $recordedBy, StaffClockSource $source = StaffClockSource::PIN, ?CarbonInterface $occurredAt = null, ?string $reason = null): StaffClockEvent
    {
        ClockRules::authorise($user, $location, $recordedBy, $source, $reason);

        return DB::transaction(function () use ($user, $location, $recordedBy, $source, $occurredAt, $reason): StaffClockEvent {
            $open = WorkedHours::openPeriodFor($user);
            if ($open !== null) {
                throw new DomainException(__('Ya tienes una jornada abierta en :sede. Ficha la salida allí primero.', ['sede' => $open->location->name]));
            }

            // Stored as a UTC instant: Eloquent writes a datetime's wall-clock digits without converting, so a
            // Madrid time must be normalised first or it would be stored 1–2 h off.
            $at = CarbonImmutable::instance($occurredAt ?? now())->setTimezone(config('app.timezone') ?: 'UTC');
            if ($at->greaterThan(now())) {
                throw new InvalidArgumentException(__('La hora no puede ser futura.'));
            }

            $event = StaffClockEvent::create([
                'organisation_id' => $location->organisation_id,
                'user_id' => $user->id,
                'location_id' => $location->id,
                'type' => StaffClockType::IN,
                'occurred_at' => $at,
                'recorded_at' => now(),
                'business_date' => BusinessDay::date($location, $at)->toDateString(),
                'source' => $source,
                'recorded_by' => $recordedBy->id,
                'reason' => filled($reason) ? trim((string) $reason) : null,
            ]);

            (new RecordAuditLog)->handle($source === StaffClockSource::MANAGER_CORRECTION ? 'staff.clock.corrected' : 'staff.clock.in', $event, null, [
                'user_id' => $user->id, 'location_id' => $location->id, 'occurred_at' => $event->occurred_at->toIso8601String(),
            ]);

            return $event;
        });
    }
}
