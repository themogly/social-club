<?php

namespace App\ViewModels;

use App\Enums\Role;
use App\Filament\Pages\RegistroJornada;
use App\Models\Location;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\BusinessDay;
use App\Support\Period;
use App\Support\WorkedHours;
use App\ViewModels\Reports\ReportColumn;
use App\ViewModels\Reports\ReportTable;
use Carbon\CarbonImmutable;

/**
 * Staff hours at a glance (prompt 285) — the ONE reader behind every hours figure on the dashboard and on the
 * "Horas del personal" report. Built from ONE `WorkedHours::periods()` call covering everything any figure needs (the
 * selected period, the previous one for the delta, and the 31-day alert lookback), memoised for the request, so five
 * charts are one pass over the event log — and nothing here pairs events itself.
 *
 * The counting rules, in one place:
 *  - a complete period counts its minutes; an open period TODAY counts up to now (in progress);
 *  - an open period from a PAST day ("Sin fichar salida") counts ZERO and is reported as a number — a chart must not
 *    invent the duration 281 refuses to invent;
 *  - a period with a self-declared or corrected end/start counts, in its own "declared" bucket;
 *  - annulled events count for nothing (WorkedHours already skips them).
 * Periods belong to their business day; only the coverage heatmap splits minutes across the clock hours they covered.
 * Hours only — never money.
 *
 * @phpstan-import-type WorkedPeriod from WorkedHours
 */
class StaffHours
{
    /** The alerts' window — WorkedHours' own lookback, so an alert never vanishes because the owner picked "Hoy". */
    public const ALERT_LOOKBACK_DAYS = 31;

    /** Past this many days, "hours per day" becomes "hours per week". */
    public const DAILY_LIMIT_DAYS = 62;

    /** @var array<string, array{name: string, left: bool}> */
    private array $people = [];

    /** @var array<string, Location> */
    private array $locations = [];

    /** @var array<string, string> business "today" per sede */
    private array $today = [];

    /** @var list<WorkedPeriod> */
    private array $periods = [];

    /** @var list<array{user_id: string, location_id: string, business_date: string}> */
    private array $unclocked = [];

    private string $fromDate;

    private string $toDate;

    private string $alertFrom;

    private CarbonImmutable $now;

    /**
     * @param  list<string>  $locationIds  already narrowed to what the viewer may see
     */
    private function __construct(public readonly array $locationIds, public readonly Period $period)
    {
        $this->now = CarbonImmutable::now();
        [$this->fromDate, $this->toDate] = self::dates($period);
    }

    /**
     * The sedes a person sees hours for: `WorkedHours::viewableLocationIds()` (every sede for an owner, their own for a
     * holder of `staff.hours.view`, none otherwise), narrowed to one sede when given — the top bar's, or a report's scope.
     *
     * @return list<string>
     */
    public static function visibleLocationIds(User $user, ?string $narrowTo): array
    {
        $visible = WorkedHours::viewableLocationIds($user);

        return $narrowTo === null ? $visible : array_values(array_intersect($visible, [$narrowTo]));
    }

    /** May this person see any of it here? STAFF never do on the dashboard, whatever they hold. */
    public static function visibleOnDashboard(User $user): bool
    {
        return $user->can('staff.hours.view') && $user->hasAnyRole([Role::OWNER->value, Role::MANAGER->value]);
    }

    /**
     * The hours for a viewer and period — memoised for the request. `$locationIds` null = what the viewer may see,
     * narrowed to the top bar's sede; a given list is intersected with what they may see, never trusted.
     *
     * @param  list<string>|null  $locationIds
     */
    public static function for(User $user, Period $period, ?array $locationIds = null): self
    {
        $viewable = WorkedHours::viewableLocationIds($user);
        $ids = $locationIds === null
            ? self::visibleLocationIds($user, app(ActiveScope::class)->locationId())
            : array_values(array_intersect($locationIds, $viewable));
        sort($ids);

        $key = 'staff-hours|'.$user->id.'|'.implode(',', $ids).'|'.$period->start->getTimestamp().'|'.$period->end->getTimestamp().'|'.now()->format('YmdHi');
        if (request()->attributes->has($key)) {
            /** @var self */
            return request()->attributes->get($key);
        }

        $hours = new self($ids, $period);
        $hours->load();
        request()->attributes->set($key, $hours);

        return $hours;
    }

    /**
     * The period's business dates as [from, to) strings, read in the period's own sede timezone.
     *
     * @return array{0: string, 1: string}
     */
    private static function dates(Period $period): array
    {
        $tz = $period->location?->timezone ?: Period::displayTimezone($period->location);

        return [$period->start->setTimezone($tz)->toDateString(), $period->end->setTimezone($tz)->toDateString()];
    }

    /** The ONE read: every period any figure here needs, the names and the unclocked cross-check. */
    private function load(): void
    {
        [$previousFrom] = self::dates($this->period->previous());

        if ($this->locationIds === []) {
            $this->alertFrom = $this->fromDate;

            return;
        }

        $this->locations = Location::query()->withoutGlobalScopes()->whereIn('id', $this->locationIds)->get()->keyBy('id')->all();
        foreach ($this->locations as $id => $location) {
            $this->today[$id] = BusinessDay::today($location);
        }

        $earliestToday = min($this->today ?: [now()->toDateString()]);
        $latestToday = max($this->today ?: [now()->toDateString()]);
        $this->alertFrom = CarbonImmutable::parse($earliestToday)->subDays(self::ALERT_LOOKBACK_DAYS)->toDateString();

        $from = CarbonImmutable::parse(min($previousFrom, $this->fromDate, $this->alertFrom));
        $to = CarbonImmutable::parse(max($this->toDate, CarbonImmutable::parse($latestToday)->addDay()->toDateString()));

        $this->periods = WorkedHours::periods(null, $this->locationIds, $from, $to);
        $this->unclocked = WorkedHours::unclockedActivity($this->locationIds, $from, $to, $this->periods);

        $userIds = array_values(array_unique(array_merge(array_column($this->periods, 'user_id'), array_column($this->unclocked, 'user_id'))));
        foreach (User::withTrashed()->whereIn('id', $userIds)->get(['id', 'name', 'deleted_at']) as $user) {
            $this->people[(string) $user->id] = ['name' => (string) $user->name, 'left' => $user->deleted_at !== null];
        }
    }

    // --- The counting rules ------------------------------------------------------------------------------------------

    /** @param WorkedPeriod $p */
    private function isOpenToday(array $p): bool
    {
        return $p['out'] === null && $p['business_date'] >= ($this->today[$p['location_id']] ?? $p['business_date']);
    }

    /** @param WorkedPeriod $p */
    private function isOpenPast(array $p): bool
    {
        return $p['out'] === null && ! $this->isOpenToday($p);
    }

    /** @param WorkedPeriod $p */
    private function minutesOf(array $p): int
    {
        if ($p['out'] !== null) {
            return (int) $p['minutes'];
        }

        return $this->isOpenToday($p) ? max(0, (int) $p['in']->occurred_at->diffInMinutes($this->now)) : 0;
    }

    /** @param WorkedPeriod $p */
    private function isDeclared(array $p): bool
    {
        return $p['in']->source->isDeclared() || ($p['out']?->source->isDeclared() ?? false);
    }

    /**
     * @return list<WorkedPeriod>
     */
    private function inWindow(string $from, string $to): array
    {
        return array_values(array_filter($this->periods, fn (array $p): bool => $p['business_date'] >= $from && $p['business_date'] < $to));
    }

    // --- What the screens read ---------------------------------------------------------------------------------------

    /** Is there anything at all in the selected period (periods, open shifts or unclocked days)? */
    public function isEmpty(): bool
    {
        return $this->perPerson() === [];
    }

    public function name(string $userId): string
    {
        return $this->people[$userId]['name'] ?? __('Persona desconocida');
    }

    public function hasLeft(string $userId): bool
    {
        return $this->people[$userId]['left'] ?? false;
    }

    /** A person as a screen shows them: the name, and "(ya no está)" after it once they have left. */
    public function label(string $userId): string
    {
        return $this->hasLeft($userId) ? __(':name (ya no está)', ['name' => $this->name($userId)]) : $this->name($userId);
    }

    /**
     * Who is clocked in right now — today's open periods, whatever the selected period ("now" has no period).
     *
     * @return list<array{user_id: string, name: string, left: bool, location: string, in_at: CarbonImmutable, in_time: string, minutes: int}>
     */
    public function now(): array
    {
        $rows = [];
        foreach ($this->periods as $p) {
            if ($this->isOpenToday($p)) {
                $rows[] = [
                    'user_id' => $p['user_id'],
                    'name' => $this->name($p['user_id']),
                    'left' => $this->hasLeft($p['user_id']),
                    'location' => (string) ($this->locations[$p['location_id']]->name ?? ''),
                    'in_at' => CarbonImmutable::instance($p['in']->occurred_at),
                    // In the person's OWN sede's time — in the rollup the canonical sede's zone would misstate it.
                    'in_time' => local_datetime($p['in']->occurred_at, 'H:i', $this->locations[$p['location_id']] ?? null),
                    'minutes' => $this->minutesOf($p),
                ];
            }
        }
        usort($rows, fn (array $a, array $b): int => $a['in_at'] <=> $b['in_at']);

        return $rows;
    }

    /** Open periods from a past day in the last 31 days — the "jornadas sin fichar salida" alert. */
    public function openShiftsCount(): int
    {
        return count(array_filter($this->periods, fn (array $p): bool => $this->isOpenPast($p) && $p['business_date'] >= $this->alertFrom));
    }

    /** Days with counter sign-ins and no period, in the last 31 days — the "actividad sin fichar" alert. */
    public function unclockedDaysCount(): int
    {
        return count(array_filter($this->unclocked, fn (array $u): bool => $u['business_date'] >= $this->alertFrom));
    }

    /**
     * One row per person in the selected period, by total descending.
     *
     * @return list<array{user_id: string, name: string, left: bool, clocked_minutes: int, declared_minutes: int, total_minutes: int, shifts: int, days: int, longest_minutes: int, average_minutes: int, in_progress: int, open_past: int, unclocked_days: int, by_location: array<string, int>, sedes: list<string>}>
     */
    public function perPerson(): array
    {
        return $this->perPersonBetween($this->fromDate, $this->toDate);
    }

    /**
     * @return list<array{user_id: string, name: string, left: bool, clocked_minutes: int, declared_minutes: int, total_minutes: int, shifts: int, days: int, longest_minutes: int, average_minutes: int, in_progress: int, open_past: int, unclocked_days: int, by_location: array<string, int>, sedes: list<string>}>
     */
    private function perPersonBetween(string $from, string $to): array
    {
        $rows = [];
        $blank = fn (string $userId): array => [
            'user_id' => $userId, 'name' => $this->name($userId), 'left' => $this->hasLeft($userId),
            'clocked_minutes' => 0, 'declared_minutes' => 0, 'total_minutes' => 0, 'shifts' => 0, 'days' => 0,
            'longest_minutes' => 0, 'average_minutes' => 0, 'in_progress' => 0, 'open_past' => 0, 'unclocked_days' => 0,
            'by_location' => [], 'sedes' => [], 'dates' => [],
        ];

        foreach ($this->inWindow($from, $to) as $p) {
            $row = $rows[$p['user_id']] ?? $blank($p['user_id']);

            if ($this->isOpenPast($p)) {
                $row['open_past']++;
            } else {
                $minutes = $this->minutesOf($p);
                $row[$this->isDeclared($p) ? 'declared_minutes' : 'clocked_minutes'] += $minutes;
                $row['total_minutes'] += $minutes;
                $row['shifts']++;
                $row['longest_minutes'] = max($row['longest_minutes'], $minutes);
                $row['in_progress'] += $this->isOpenToday($p) ? 1 : 0;
                $row['dates'][$p['business_date']] = true;
                $row['by_location'][$p['location_id']] = ($row['by_location'][$p['location_id']] ?? 0) + $minutes;
            }

            $rows[$p['user_id']] = $row;
        }

        foreach ($this->unclocked as $u) {
            if ($u['business_date'] >= $from && $u['business_date'] < $to) {
                $row = $rows[$u['user_id']] ?? $blank($u['user_id']);
                $row['unclocked_days']++;
                $rows[$u['user_id']] = $row;
            }
        }

        $result = [];
        foreach ($rows as $row) {
            $row['days'] = count($row['dates']);
            $row['average_minutes'] = $row['shifts'] > 0 ? (int) round($row['total_minutes'] / $row['shifts']) : 0;
            $row['sedes'] = array_map(fn (string $id): string => (string) ($this->locations[$id]->name ?? ''), array_keys($row['by_location']));
            unset($row['dates']);
            $result[] = $row;
        }

        usort($result, fn (array $a, array $b): int => [$b['total_minutes'], $a['name']] <=> [$a['total_minutes'], $b['name']]);

        return $result;
    }

    /**
     * The four headline figures for the selected period, with the previous period of the same length for the delta.
     *
     * @return array{total_minutes: int, previous_total_minutes: int, people: int, shifts: int, average_shift_minutes: int, declared_minutes: int, declared_percent: int, open_past: int}
     */
    public function summary(): array
    {
        $people = $this->perPerson();
        [$previousFrom, $previousTo] = self::dates($this->period->previous());
        $total = (int) array_sum(array_column($people, 'total_minutes'));
        $shifts = (int) array_sum(array_column($people, 'shifts'));
        $declared = (int) array_sum(array_column($people, 'declared_minutes'));

        return [
            'total_minutes' => $total,
            'previous_total_minutes' => (int) array_sum(array_column($this->perPersonBetween($previousFrom, $previousTo), 'total_minutes')),
            'people' => count(array_filter($people, fn (array $r): bool => $r['total_minutes'] > 0)),
            'shifts' => $shifts,
            'average_shift_minutes' => $shifts > 0 ? (int) round($total / $shifts) : 0,
            'declared_minutes' => $declared,
            'declared_percent' => $total > 0 ? (int) round($declared * 100 / $total) : 0,
            'open_past' => (int) array_sum(array_column($people, 'open_past')),
        ];
    }

    /**
     * Minutes per business day (per ISO week past 62 days), per person — gaps and heavy days, stacked by person.
     *
     * @return array{keys: list<string>, labels: list<string>, by_week: bool, series: array<string, list<int>>}
     */
    public function perDay(): array
    {
        $start = CarbonImmutable::parse($this->fromDate);
        $end = CarbonImmutable::parse($this->toDate);
        $byWeek = $start->diffInDays($end) > self::DAILY_LIMIT_DAYS;

        $keys = [];
        $labels = [];
        for ($cursor = $byWeek ? $start->startOfWeek() : $start; $cursor < $end; $cursor = $byWeek ? $cursor->addWeek() : $cursor->addDay()) {
            $keys[] = $cursor->toDateString();
            $labels[] = $byWeek ? __('Sem. :date', ['date' => $cursor->format('d/m')]) : $cursor->format('d/m');
        }
        $index = array_flip($keys);

        $series = [];
        foreach ($this->inWindow($this->fromDate, $this->toDate) as $p) {
            $date = CarbonImmutable::parse($p['business_date']);
            $key = $byWeek ? $date->startOfWeek()->toDateString() : $p['business_date'];
            $series[$p['user_id']] ??= array_fill(0, count($keys), 0);
            $series[$p['user_id']][$index[$key]] += $this->minutesOf($p);
        }

        return ['keys' => $keys, 'labels' => $labels, 'by_week' => $byWeek, 'series' => $series];
    }

    /**
     * Staffed minutes by weekday (0 = Sunday, like footfall) × hour, each period split across the clock hours it covered
     * in its sede's timezone — so it lines up with the member footfall heatmap for the same scope and period.
     *
     * @return array{matrix: array<int, array<int, int>>, max: int}
     */
    public function coverage(): array
    {
        $seconds = array_fill(0, 7, array_fill(0, 24, 0));

        foreach ($this->inWindow($this->fromDate, $this->toDate) as $p) {
            if ($this->isOpenPast($p)) {
                continue;
            }
            $tz = ($this->locations[$p['location_id']] ?? null)?->timezone ?: 'Europe/Madrid';
            $cursor = CarbonImmutable::instance($p['in']->occurred_at)->setTimezone($tz);
            $end = ($p['out'] !== null ? CarbonImmutable::instance($p['out']->occurred_at) : $this->now)->setTimezone($tz);

            while ($cursor < $end) {
                $next = $cursor->startOfHour()->addHour();
                $slice = $next < $end ? $next : $end;
                $seconds[$cursor->dayOfWeek][$cursor->hour] += $slice->getTimestamp() - $cursor->getTimestamp();
                $cursor = $slice;
            }
        }

        $matrix = array_map(fn (array $hours): array => array_map(fn (int $s): int => intdiv($s, 60), $hours), $seconds);
        $max = max(array_map('max', $matrix));

        return ['matrix' => $matrix, 'max' => $max];
    }

    /** The per-person table — the report's primary, sortable, exportable table. */
    public function table(): ReportTable
    {
        $month = CarbonImmutable::parse($this->fromDate)->format('Y-m');
        $rows = array_map(fn (array $r): array => [
            'persona' => $this->label($r['user_id']),
            'persona__url' => RegistroJornada::getUrl(['personId' => $r['user_id'], 'month' => $month]),
            'sedes' => implode(', ', $r['sedes']) ?: '—',
            'dias' => $r['days'],
            'total' => $r['total_minutes'],
            'media' => $r['average_minutes'],
            'mas_larga' => $r['longest_minutes'],
            'declarada' => $r['declared_minutes'],
            'abiertas' => $r['open_past'],
            'sin_fichar' => $r['unclocked_days'],
        ], $this->perPerson());

        return new ReportTable(
            key: 'staff_hours',
            title: __('Horas por persona'),
            columns: [
                ReportColumn::text('persona', __('Persona')),
                ReportColumn::text('sedes', __('Sedes')),
                ReportColumn::number('dias', __('Días trabajados'), total: false),
                ReportColumn::hours('total', __('Horas')),
                ReportColumn::duration('media', __('Jornada media')),
                ReportColumn::duration('mas_larga', __('Jornada más larga')),
                ReportColumn::hours('declarada', __('Declaradas o corregidas')),
                ReportColumn::number('abiertas', __('Sin fichar salida')),
                ReportColumn::number('sin_fichar', __('Días sin fichar')),
            ],
            rows: $rows,
            totals: [
                'total' => (int) array_sum(array_column($rows, 'total')),
                'declarada' => (int) array_sum(array_column($rows, 'declarada')),
                'abiertas' => (int) array_sum(array_column($rows, 'abiertas')),
                'sin_fichar' => (int) array_sum(array_column($rows, 'sin_fichar')),
            ],
            empty: __('Nadie ha fichado en este período.'),
            defaultSort: 'total',
            sortable: true,
        );
    }

    /** @return array<string, string> the sede names in scope, by id */
    public function locationNames(): array
    {
        return array_map(fn (Location $l): string => (string) $l->name, $this->locations);
    }
}
