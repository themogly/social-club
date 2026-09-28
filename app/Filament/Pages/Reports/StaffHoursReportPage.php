<?php

namespace App\Filament\Pages\Reports;

use App\Enums\Role;
use App\Models\Location;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Duration;
use App\Support\Footfall;
use App\Support\Period;
use App\Support\WorkedHours;
use App\ViewModels\Reports\AbstractReport;
use App\ViewModels\Reports\StaffHoursReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Horas del personal (prompt 285) — "how it went": stat cards, hours per person by sede, hours per day, staff coverage
 * beside member footfall, and the per-person table (CSV/PDF). The shared Informes shape, but gated on
 * `staff.hours.view` and scoped to the sedes that permission shows (`WorkedHours::viewableLocationIds`), not to
 * `reports.view*` — a manager who may see hours sees their own sedes' staff, never another's.
 */
class StaffHoursReportPage extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 45;

    protected static ?string $slug = 'informes/horas-del-personal';

    public static function getNavigationLabel(): string
    {
        return __('Horas del personal');
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('staff.hours.view');
    }

    protected function makeReport(string $organisationId, ?array $locationIds, Period $period): AbstractReport
    {
        return new StaffHoursReport($organisationId, $locationIds ?? $this->allowedLocationIds(), $period, $this->user());
    }

    /** @return list<string> */
    protected function allowedLocationIds(): array
    {
        return WorkedHours::viewableLocationIds($this->user());
    }

    protected function canSeeAll(): bool
    {
        return $this->user()->hasRole(Role::OWNER->value);
    }

    /** "All" means every sede this person may see — never wider; a named sede must be one of them. */
    protected function resolveLocationIds(): array
    {
        $scope = $this->scope ?? $this->defaultScope();
        $allowed = $this->allowedLocationIds();

        if ($scope === 'all' || $scope === null) {
            return $allowed;
        }

        abort_unless(in_array($scope, $allowed, true), 403);

        return [$scope];
    }

    /** @return array<string, string> */
    protected function scopeOptions(): array
    {
        $locations = Location::query()->withoutGlobalScopes()
            ->whereIn('id', $this->allowedLocationIds())->orderBy('name')->pluck('name', 'id')->all();

        return count($locations) > 1
            ? ['all' => $this->canSeeAll() ? __('Todas las sedes') : __('Tus sedes')] + $locations
            : $locations;
    }

    /** The top bar's sede when it is one of theirs, else all of theirs. */
    protected function defaultScope(): ?string
    {
        $allowed = $this->allowedLocationIds();
        $active = app(ActiveScope::class)->locationId();

        if ($active !== null && in_array($active, $allowed, true)) {
            return $active;
        }

        return count($allowed) > 1 ? 'all' : ($allowed[0] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var StaffHoursReport $report */
        $report = $this->report();
        $hours = $report->hours();
        $period = $this->resolvePeriod();
        $summary = $hours->summary();
        $locationIds = $this->resolveLocationIds();

        // Member footfall for the SAME scope and period, so coverage sits beside the demand it answers.
        [$start, $end] = $period->bounds();
        $footfall = array_fill(0, 7, array_fill(0, 24, 0));
        foreach (Location::query()->withoutGlobalScopes()->whereIn('id', $locationIds)->get() as $location) {
            foreach (Footfall::byHourAndWeekday($location, $start, $end) as $weekday => $byHour) {
                foreach ($byHour as $hour => $count) {
                    $footfall[$weekday][$hour] += $count;
                }
            }
        }

        return parent::getViewData() + [
            'chartsView' => 'filament.pages.reports.partials.staff-hours',
            'staffCards' => $this->cards($summary),
            'staffChartProps' => ['periodKey' => $this->period, 'customStart' => $this->customStart, 'customEnd' => $this->customEnd, 'locationIds' => $locationIds],
            'staffChartKey' => implode('-', [$this->period, $this->customStart, $this->customEnd, $this->scope]),
            'perDayByWeek' => $hours->perDay()['by_week'],
            'coverage' => $hours->coverage(),
            'footfall' => ['matrix' => $footfall, 'max' => max(array_map('max', $footfall))],
            'staffSummaryLine' => trans_choice(':count persona ha fichado :hours en este período.|:count personas han fichado :hours en este período.', $summary['people'], ['count' => $summary['people'], 'hours' => Duration::hours($summary['total_minutes'])]),
        ];
    }

    /**
     * The four stat cards (the shared `<x-dashboard.stat-card>` shape).
     *
     * @param  array{total_minutes: int, previous_total_minutes: int, people: int, shifts: int, average_shift_minutes: int, declared_minutes: int, declared_percent: int, open_past: int}  $s
     * @return list<array<string, mixed>>
     */
    private function cards(array $s): array
    {
        $diff = $s['total_minutes'] - $s['previous_total_minutes'];
        $dir = $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'flat');
        $pct = $s['previous_total_minutes'] !== 0 ? (int) round(abs($diff) / $s['previous_total_minutes'] * 100) : ($s['total_minutes'] !== 0 ? 100 : 0);

        return [
            [
                'key' => 'hours', 'label' => __('Horas trabajadas'), 'icon' => Heroicon::OutlinedClock,
                'value' => Duration::hours($s['total_minutes']),
                'sub' => __('Período anterior: :h', ['h' => Duration::hours($s['previous_total_minutes'])]),
                // Neutral tone: more hours is neither good nor bad — it is the fact the owner reads.
                'delta' => ['dir' => $dir, 'pct' => $pct, 'tone' => 'muted', 'label' => ($dir === 'up' ? '+' : ($dir === 'down' ? '−' : '')).$pct.'%'],
            ],
            [
                'key' => 'people', 'label' => __('Personas que han trabajado'), 'icon' => Heroicon::OutlinedUsers,
                'value' => (string) $s['people'],
                'sub' => trans_choice(':count jornada|:count jornadas', $s['shifts'], ['count' => $s['shifts']]),
            ],
            [
                'key' => 'average', 'label' => __('Jornada media'), 'icon' => Heroicon::OutlinedScale,
                'value' => Duration::format($s['average_shift_minutes']),
                'sub' => $s['open_past'] > 0 ? trans_choice(':count sin fichar salida (no cuenta)|:count sin fichar salida (no cuentan)', $s['open_past'], ['count' => $s['open_past']]) : null,
                'flag' => $s['open_past'] > 0,
            ],
            [
                'key' => 'declared', 'label' => __('Horas declaradas o corregidas'), 'icon' => Heroicon::OutlinedPencilSquare,
                'value' => $s['declared_percent'].' %',
                'sub' => Duration::hours($s['declared_minutes']),
            ],
        ];
    }
}
