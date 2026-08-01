<?php

namespace App\ViewModels\Reports;

use App\Models\CheckIn;
use App\Support\Footfall;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Asistencia — the entry–exit sheet a CSC must be able to produce, footfall by hour (the
 * staffing chart), occupancy peaks, dwell time and visits per member. Everything is
 * derived live from the check-in log; the per-hour breakdown reuses `App\Support\Footfall`.
 */
class AttendanceReport extends AbstractReport
{
    /** @var Collection<int, CheckIn>|null the period's check-ins, loaded once */
    private ?Collection $checkInsCache = null;

    /** @var array{members: Collection<int|string, \stdClass>, operators: Collection<int|string, string>, locations: Collection<int|string, string>}|null */
    private ?array $mapsCache = null;

    private int $peak = 0;

    private int $uniqueMembers = 0;

    private int $avgDuration = 0;

    public function key(): string
    {
        return 'asistencia';
    }

    public function title(): string
    {
        return __('Informe de asistencia');
    }

    protected function build(): array
    {
        return [
            $this->entryExit(),
            $this->footfallByHour(),
            $this->visitsPerMember(),
        ];
    }

    public function summary(): array
    {
        $this->tables();

        return [
            ['label' => __('Visitas'), 'value' => (string) $this->checkIns()->count()],
            ['label' => __('Socios únicos'), 'value' => (string) $this->uniqueMembers],
            ['label' => __('Pico de aforo'), 'value' => (string) $this->peak],
            ['label' => __('Estancia media'), 'value' => __(':n min', ['n' => $this->avgDuration])],
        ];
    }

    /**
     * @return Collection<int, CheckIn>
     */
    private function checkIns(): Collection
    {
        if ($this->checkInsCache !== null) {
            return $this->checkInsCache;
        }

        [$start, $end] = $this->bounds();

        return $this->checkInsCache = CheckIn::query()->withoutGlobalScopes()
            ->whereIn('location_id', $this->resolvedLocationIds())
            ->where('checked_in_at', '>=', $start)->where('checked_in_at', '<', $end)
            ->orderBy('checked_in_at')
            ->get();
    }

    /**
     * Member / operator / location names in three batched lookups (no N+1, no per-relation
     * nullsafe) — the same shape `DashboardCharts::recentTransactions` uses.
     *
     * @return array{members: Collection<int|string, \stdClass>, operators: Collection<int|string, string>, locations: Collection<int|string, string>}
     */
    private function maps(): array
    {
        if ($this->mapsCache !== null) {
            return $this->mapsCache;
        }

        $checkIns = $this->checkIns();

        return $this->mapsCache = [
            'members' => DB::table('members')
                ->whereIn('id', $checkIns->pluck('member_id')->filter()->unique()->all())
                ->get(['id', 'member_no', 'first_name', 'last_name'])->keyBy('id'),
            'operators' => DB::table('users')
                ->whereIn('id', $checkIns->pluck('operator_id')->filter()->unique()->all())
                ->pluck('name', 'id'),
            'locations' => DB::table('locations')
                ->whereIn('id', $this->resolvedLocationIds())->pluck('name', 'id'),
        ];
    }

    private function memberNo(?string $id): string
    {
        return data_get($this->maps()['members']->get($id), 'member_no') ?? '—';
    }

    private function memberName(?string $id): string
    {
        $member = $this->maps()['members']->get($id);

        return $member !== null ? trim($member->first_name.' '.$member->last_name) : '—';
    }

    // --- Entry–exit sheet -----------------------------------------------------------

    private function entryExit(): ReportTable
    {
        $this->computeDerived();

        $rows = $this->checkIns()->map(fn (CheckIn $c): array => [
            'member_no' => $this->memberNo($c->member_id),
            'socio' => $this->memberName($c->member_id),
            'sede' => $this->maps()['locations']->get($c->location_id) ?? '—',
            'entrada' => $c->checked_in_at,
            'salida' => $c->checked_out_at,
            'duracion' => $c->checked_out_at !== null ? (int) $c->checked_in_at->diffInMinutes($c->checked_out_at) : null,
            'operador' => $c->operator_id !== null ? ($this->maps()['operators']->get($c->operator_id) ?? '—') : '—',
        ])->all();

        $totalDuration = array_sum(array_map(fn (array $r): int => (int) ($r['duracion'] ?? 0), $rows));

        return new ReportTable(
            key: 'entry_exit',
            title: __('Hoja de entradas y salidas'),
            columns: [
                ReportColumn::text('member_no', __('Nº')),
                ReportColumn::text('socio', __('Socio')),
                ReportColumn::text('sede', __('Sede')),
                ReportColumn::datetime('entrada', __('Entrada')),
                ReportColumn::datetime('salida', __('Salida')),
                ReportColumn::number('duracion', __('Duración (min)')),
                ReportColumn::text('operador', __('Operador')),
            ],
            rows: $rows,
            totals: ['duracion' => $totalDuration],
            empty: __('Sin entradas registradas en este período'),
            emptyHint: __('Las entradas y salidas del control de acceso aparecerán aquí.'),
            defaultSort: 'entrada',
            defaultSortDir: 'asc',
            sortable: true,
        );
    }

    // --- Footfall by hour (reuses App\Support\Footfall) -----------------------------

    private function footfallByHour(): ReportTable
    {
        [$start, $end] = $this->bounds();

        $hours = array_fill(0, 24, 0);
        foreach ($this->scopedLocations() as $location) {
            foreach (Footfall::byHourAndWeekday($location, $start, $end) as $byHour) {
                foreach ($byHour as $hour => $count) {
                    $hours[$hour] += $count;
                }
            }
        }

        $rows = [];
        foreach ($hours as $hour => $count) {
            if ($count > 0) {
                $rows[] = ['hora' => sprintf('%02d:00', $hour), 'entradas' => $count];
            }
        }

        return new ReportTable(
            key: 'footfall',
            title: __('Afluencia por hora'),
            columns: [
                ReportColumn::text('hora', __('Hora'), sortable: false),
                ReportColumn::number('entradas', __('Entradas')),
            ],
            rows: $rows,
            totals: ['entradas' => array_sum(array_column($rows, 'entradas'))],
            empty: __('Sin entradas registradas en este período'),
        );
    }

    // --- Visits per member ----------------------------------------------------------

    private function visitsPerMember(): ReportTable
    {
        /** @var array<string, array{member_no: string, socio: string, visitas: int, total_min: int, closed: int}> $byMember */
        $byMember = [];
        foreach ($this->checkIns() as $c) {
            $key = $c->member_id;
            $byMember[$key] ??= [
                'member_no' => $this->memberNo($c->member_id),
                'socio' => $this->memberName($c->member_id),
                'visitas' => 0, 'total_min' => 0, 'closed' => 0,
            ];
            $byMember[$key]['visitas']++;
            if ($c->checked_out_at !== null) {
                $byMember[$key]['total_min'] += (int) $c->checked_in_at->diffInMinutes($c->checked_out_at);
                $byMember[$key]['closed']++;
            }
        }

        $rows = array_map(fn (array $m): array => [
            'member_no' => $m['member_no'],
            'socio' => $m['socio'],
            'visitas' => $m['visitas'],
            'media' => $m['closed'] > 0 ? intdiv($m['total_min'], $m['closed']) : 0,
        ], array_values($byMember));
        usort($rows, fn (array $a, array $b): int => $b['visitas'] <=> $a['visitas']);

        return new ReportTable(
            key: 'per_member',
            title: __('Visitas por socio'),
            columns: [
                ReportColumn::text('member_no', __('Nº'), sortable: false),
                ReportColumn::text('socio', __('Socio'), sortable: false),
                ReportColumn::number('visitas', __('Visitas')),
                ReportColumn::number('media', __('Estancia media (min)'), total: false),
            ],
            rows: $rows,
            totals: ['visitas' => array_sum(array_column($rows, 'visitas'))],
            empty: __('Sin visitas en este período'),
        );
    }

    /** Peak concurrent occupancy, unique members and mean dwell — one sweep of the loaded log. */
    private function computeDerived(): void
    {
        $checkIns = $this->checkIns();
        $this->uniqueMembers = $checkIns->pluck('member_id')->unique()->count();

        [, $end] = $this->bounds();
        $events = [];
        $durations = [];
        foreach ($checkIns as $c) {
            $events[] = [$c->checked_in_at->getTimestamp(), 1];
            $out = $c->checked_out_at ?? $end;
            $events[] = [$out->getTimestamp(), -1];
            if ($c->checked_out_at !== null) {
                $durations[] = (int) $c->checked_in_at->diffInMinutes($c->checked_out_at);
            }
        }

        // Sort by time; process exits before entries at the same instant so simultaneous
        // swaps do not inflate the peak.
        usort($events, fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        $running = 0;
        $peak = 0;
        foreach ($events as [$_ts, $delta]) {
            $running += $delta;
            $peak = max($peak, $running);
        }
        $this->peak = $peak;
        $this->avgDuration = $durations !== [] ? intdiv(array_sum($durations), count($durations)) : 0;
    }
}
