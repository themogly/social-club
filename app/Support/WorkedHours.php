<?php

namespace App\Support;

use App\Enums\Role;
use App\Enums\StaffClockSource;
use App\Enums\StaffClockType;
use App\Models\Location;
use App\Models\StaffClockEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * THE one reader of the registro de jornada (prompt 281, Ben's 280). Periods are DERIVED from the append-only event log,
 * never stored, and only here: an IN opens a period, the next OUT closes it; an annulled event is skipped (and reported
 * separately, struck through — never hidden); a period is `complete` or `open`, and flagged when a typed time is involved.
 *
 * It never reads hours from the audit log. The only audit read is `unclockedActivity()`: a cross-check that names days
 * with counter sign-ins and no period — one query per report, never a source of hours.
 *
 * @phpstan-type WorkedPeriod array{user_id: string, location_id: string, business_date: string, in: StaffClockEvent, out: ?StaffClockEvent, minutes: ?int, status: string, flags: list<string>}
 */
class WorkedHours
{
    /** How far before a report window events are read, so a period that started just before it still pairs. */
    private const LOOKBACK_DAYS = 31;

    /** The person's open period anywhere in their organisation, or null — the latest unannulled IN with no OUT after it. */
    public static function openPeriodFor(User $user): ?StaffClockEvent
    {
        $open = null;

        foreach (self::effectiveEvents(self::eventsQuery()->where('user_id', $user->id)->get()) as $event) {
            $open = $event->type === StaffClockType::IN ? $event : null;
        }

        return $open;
    }

    /**
     * Periods whose business day falls in [from, to), at these sedes, for these people (null = everyone there).
     *
     * @param  list<string>|null  $userIds
     * @param  list<string>  $locationIds
     * @return list<WorkedPeriod>
     */
    public static function periods(?array $userIds, array $locationIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $events = self::eventsQuery()
            ->when($userIds !== null, fn ($q) => $q->whereIn('user_id', $userIds))
            ->where('occurred_at', '>=', $from->copy()->subDays(self::LOOKBACK_DAYS))
            ->where('occurred_at', '<', $to->copy()->addDay())
            ->get();

        $periods = [];
        foreach ($events->groupBy('user_id') as $userEvents) {
            $open = null;
            foreach (self::effectiveEvents($userEvents) as $event) {
                if ($event->type === StaffClockType::IN) {
                    if ($open !== null) {
                        $periods[] = self::period($open, null);
                    }
                    $open = $event;
                } elseif ($open !== null) {
                    $periods[] = self::period($open, $event);
                    $open = null;
                }
            }
            if ($open !== null) {
                $periods[] = self::period($open, null);
            }
        }

        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        return array_values(array_filter($periods, fn (array $p): bool => in_array($p['location_id'], $locationIds, true)
            && $p['business_date'] >= $fromDate && $p['business_date'] < $toDate));
    }

    /**
     * Events annulled in the window — the report shows them struck through.
     *
     * @param  list<string>|null  $userIds
     * @param  list<string>  $locationIds
     * @return Collection<int, StaffClockEvent>
     */
    public static function annulled(?array $userIds, array $locationIds, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $annulledIds = self::eventsQuery()->where('type', StaffClockType::ANNUL->value)->pluck('corrects_event_id')->filter()->all();

        return self::eventsQuery()->whereIn('id', $annulledIds)
            ->when($userIds !== null, fn ($q) => $q->whereIn('user_id', $userIds))
            ->whereIn('location_id', $locationIds)
            ->where('business_date', '>=', $from->toDateString())->where('business_date', '<', $to->toDateString())
            ->get();
    }

    /**
     * Days with counter sign-ins and no period covering them — the "Actividad sin fichar" cross-check. ONE query.
     *
     * @param  list<string>  $locationIds
     * @param  list<WorkedPeriod>  $periods  the report's periods for the same window
     * @return list<array{user_id: string, location_id: string, business_date: string}>
     */
    public static function unclockedActivity(array $locationIds, CarbonInterface $from, CarbonInterface $to, array $periods): array
    {
        $locations = Location::query()->withoutGlobalScopes()->whereIn('id', $locationIds)->get()->keyBy('id');
        $covered = [];
        foreach ($periods as $p) {
            $covered[$p['user_id'].'|'.$p['business_date']] = true;
        }

        $rows = DB::table('audit_logs')
            ->where('action', 'counter.operator.signed_in')
            ->whereIn('auditable_id', $locationIds)
            ->where('created_at', '>=', $from->copy()->subDay())->where('created_at', '<', $to->copy()->addDay())
            ->get(['actor_id', 'auditable_id', 'created_at']);

        $found = [];
        foreach ($rows as $row) {
            $location = $locations->get($row->auditable_id);
            if ($location === null || $row->actor_id === null) {
                continue;
            }
            $date = BusinessDay::date($location, (string) $row->created_at)->toDateString();
            $key = $row->actor_id.'|'.$date;
            if ($date < $from->toDateString() || $date >= $to->toDateString() || isset($covered[$key])) {
                continue;
            }
            $found[$key.'|'.$row->auditable_id] = ['user_id' => (string) $row->actor_id, 'location_id' => (string) $row->auditable_id, 'business_date' => $date];
        }

        return array_values($found);
    }

    /**
     * The sedes a person may SEE everyone's hours at: all for an owner, their assigned ones for a holder of
     * `staff.hours.view`, none otherwise. (Anyone may see their OWN — that needs no permission.)
     *
     * @return list<string>
     */
    public static function viewableLocationIds(User $user): array
    {
        if (! $user->can('staff.hours.view')) {
            return [];
        }

        return $user->hasRole(Role::OWNER->value)
            ? Location::query()->withoutGlobalScopes()->sedes()->pluck('id')->all()
            : $user->locations()->pluck('locations.id')->all();
    }

    /** May this person correct hours at this sede? `staff.hours.manage`, and the sede is theirs (an owner: every sede). */
    public static function canManageAt(User $user, Location $location): bool
    {
        return $user->can('staff.hours.manage')
            && ($user->hasRole(Role::OWNER->value) || $user->locations()->whereKey($location->id)->exists());
    }

    /** @return Builder<StaffClockEvent> */
    private static function eventsQuery()
    {
        return StaffClockEvent::query()->withoutGlobalScopes()->with(['recorder', 'location'])
            ->orderBy('occurred_at')->orderBy('recorded_at')->orderBy('id');
    }

    /**
     * IN/OUT events that are not annulled, in time order.
     *
     * @param  Collection<int, StaffClockEvent>  $events
     * @return Collection<int, StaffClockEvent>
     */
    private static function effectiveEvents(Collection $events): Collection
    {
        $annulled = $events->where('type', StaffClockType::ANNUL)->pluck('corrects_event_id')->filter()->flip();
        // An annulment may target an event outside this slice; look those up too, in one query.
        $outside = StaffClockEvent::query()->withoutGlobalScopes()->where('type', StaffClockType::ANNUL->value)
            ->whereIn('corrects_event_id', $events->pluck('id'))->pluck('corrects_event_id')->flip();

        return $events->reject(fn (StaffClockEvent $e): bool => $e->type === StaffClockType::ANNUL
            || $annulled->has($e->id) || $outside->has($e->id))->values();
    }

    /** @return WorkedPeriod */
    private static function period(StaffClockEvent $in, ?StaffClockEvent $out): array
    {
        $flags = [];
        $location = $in->location;
        $today = $location !== null ? BusinessDay::today($location) : now()->toDateString();

        if ($out === null && $in->business_date->toDateString() < $today) {
            $flags[] = __('Sin fichar salida');
        }
        foreach (array_filter([$in, $out]) as $event) {
            if ($event->source === StaffClockSource::SELF_DECLARED) {
                $flags[] = __('Hora declarada');
            } elseif ($event->source === StaffClockSource::MANAGER_CORRECTION) {
                $flags[] = __('Corregido por :name', ['name' => $event->recorder->name]);
            }
        }

        return [
            'user_id' => (string) $in->user_id,
            'location_id' => (string) $in->location_id,
            'business_date' => $in->business_date->toDateString(),
            'in' => $in,
            'out' => $out,
            'minutes' => $out !== null ? (int) $in->occurred_at->diffInMinutes($out->occurred_at) : null,
            'status' => $out !== null ? 'complete' : 'open',
            'flags' => array_values(array_unique($flags)),
        ];
    }
}
