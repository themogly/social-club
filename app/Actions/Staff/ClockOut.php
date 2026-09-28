<?php

namespace App\Actions\Staff;

use App\Actions\RecordAuditLog;
use App\Enums\StaffClockSource;
use App\Enums\StaffClockType;
use App\Models\StaffClockEvent;
use App\Models\User;
use App\Support\WorkedHours;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * End the person's open period (prompt 281) — an OUT on the registro de jornada.
 *
 *   · `PIN` — "Fichar salida", confirmed by the person's own PIN; `TILL_CLOSE` — the offer after they closed the till.
 *   · `SELF_DECLARED` — a forgotten clock-out, declared by the person when they next clock in (a reason, and a time
 *     between the period's IN and now). `MANAGER_CORRECTION` — added in the panel (`staff.hours.manage`, a reason).
 *
 * NOTHING ends a period automatically: there is no sweep writing an OUT at the cutoff. An invented end time is worse
 * than an open one — it is exactly what an unalterable record must not contain.
 */
class ClockOut
{
    /**
     * @throws AuthorizationException
     * @throws DomainException
     * @throws InvalidArgumentException
     */
    public function handle(User $user, User $recordedBy, StaffClockSource $source, ?CarbonInterface $occurredAt = null, ?string $reason = null): StaffClockEvent
    {
        $open = WorkedHours::openPeriodFor($user);
        if ($open === null || $open->location === null) {
            throw new DomainException(__('No hay ninguna jornada abierta.'));
        }

        ClockRules::authorise($user, $open->location, $recordedBy, $source, $reason);

        // A UTC instant (see ClockIn) — never the wall-clock digits of a local time.
        $at = CarbonImmutable::instance($occurredAt ?? now())->setTimezone(config('app.timezone') ?: 'UTC');
        if ($at->lessThan($open->occurred_at) || $at->greaterThan(now())) {
            throw new InvalidArgumentException(__('La hora de salida tiene que estar entre la entrada (:in) y ahora.', ['in' => local_datetime($open->occurred_at, 'd/m/Y H:i', $open->location)]));
        }

        return DB::transaction(function () use ($user, $recordedBy, $source, $at, $reason, $open): StaffClockEvent {
            $location = $open->location;
            $event = StaffClockEvent::create([
                'organisation_id' => $open->organisation_id,
                'user_id' => $user->id,
                'location_id' => $open->location_id,
                'type' => StaffClockType::OUT,
                'occurred_at' => $at,
                'recorded_at' => now(),
                // The period belongs to one business day — the one it started on — so the OUT is dated with its IN.
                'business_date' => $open->business_date->toDateString(),
                'source' => $source,
                'recorded_by' => $recordedBy->id,
                'reason' => filled($reason) ? trim((string) $reason) : null,
            ]);

            (new RecordAuditLog)->handle($source === StaffClockSource::MANAGER_CORRECTION ? 'staff.clock.corrected' : 'staff.clock.out', $event, null, [
                'user_id' => $user->id, 'location_id' => $open->location_id, 'occurred_at' => $event->occurred_at->toIso8601String(),
                'in_event_id' => $open->id,
            ]);

            return $event;
        });
    }
}
