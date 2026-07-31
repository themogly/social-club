<?php

namespace App\Filament\Pages\Reports;

use App\Models\Location;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Period;
use App\Support\Spreadsheet\ReportExport;
use App\ViewModels\Reports\AbstractReport;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The one shared Informes page shape — a period picker, a location scope (owners see
 * "All"), a summary strip, a sortable primary table with a totals row and CSV/PDF export
 * actions — parameterised by a single report view-model. Every concrete report is a thin
 * subclass that names its view-model, label, icon and permission; the layout, scoping,
 * sorting and exporting all live here so the seven reports stay one coherent surface.
 *
 * Authorisation: report pages need `reports.view`; the org-wide (All / assembly) views
 * additionally need `reports.view.all`, so a manager cannot open or export another sede.
 */
abstract class ReportPage extends Page
{
    protected string $view = 'filament.pages.reports.report';

    /** today | week | month | custom — the single control every table reads. */
    public string $period = 'month';

    public ?string $customStart = null;

    public ?string $customEnd = null;

    /** A location id, or 'all' for the owner rollup. Resolved on mount. */
    public ?string $scope = null;

    public ?string $sort = null;

    public string $sortDir = 'desc';

    /** True only for org-wide reports (the assembly pack) — forces All + reports.view.all. */
    protected static bool $orgWide = false;

    /**
     * @param  list<string>|null  $locationIds
     */
    abstract protected function makeReport(string $organisationId, ?array $locationIds, Period $period): AbstractReport;

    public static function getNavigationGroup(): ?string
    {
        return __('Informes');
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();
        if ($user === null) {
            return false;
        }

        return static::$orgWide
            ? $user->can('reports.view.all')
            : $user->canAny(['reports.view', 'reports.view.all']);
    }

    public function getHeading(): string
    {
        return static::getNavigationLabel();
    }

    public function getTitle(): string
    {
        // Browser <title>: the (translated) navigation label — same source as the on-page heading —
        // never Filament's class-derived default ("Bar Sales Report Page"). Fixes every report tab.
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->scope ??= $this->defaultScope();
    }

    // --- Interactions ---------------------------------------------------------------

    public function sortBy(string $key): void
    {
        if ($this->sort === $key) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sort = $key;
        $this->sortDir = 'desc';
    }

    public function exportCsv(): StreamedResponse
    {
        $report = $this->report();
        $primary = $report->primary()->sortedBy($this->sort, $this->sortDir);
        $content = ReportExport::csv($primary);
        $filename = ReportExport::filename($report->key(), $this->resolvePeriod(), 'csv');

        return response()->streamDownload(fn () => print ($content), $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportPdf(): StreamedResponse
    {
        $report = $this->report();
        $tables = $report->tables();
        $tables[0] = $tables[0]->sortedBy($this->sort, $this->sortDir);
        $content = ReportExport::pdf($report->title(), $report->summary(), $tables, $this->scopeLabel(), $this->periodLabel());
        $filename = ReportExport::filename($report->key(), $this->resolvePeriod(), 'pdf');

        return response()->streamDownload(fn () => print ($content), $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    // --- View data ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $report = $this->report();
        $tables = $report->tables();
        $primary = $tables[0]->sortedBy($this->sort, $this->sortDir);
        $tables[0] = $primary;

        return [
            'reportTitle' => $report->title(),
            'summary' => $report->summary(),
            'tables' => $tables,
            'primaryKey' => $primary->key,
            'sortKey' => $primary->defaultSort,
            'sortDir' => $primary->defaultSortDir,
            'isEmpty' => $report->isEmpty(),
            'periodKey' => $this->period,
            'customStart' => $this->customStart,
            'customEnd' => $this->customEnd,
            'scope' => $this->scope ?? $this->defaultScope(),
            'scopeOptions' => $this->scopeOptions(),
            'showScope' => count($this->scopeOptions()) > 1,
            'scopeLabel' => $this->scopeLabel(),
            'periodLabel' => $this->periodLabel(),
        ];
    }

    // --- Resolution -----------------------------------------------------------------

    protected function report(): AbstractReport
    {
        return $this->makeReport($this->organisationId(), $this->resolveLocationIds(), $this->resolvePeriod());
    }

    public function resolvePeriod(): Period
    {
        if ($this->period === 'custom' && filled($this->customStart) && filled($this->customEnd)) {
            return Period::custom(
                CarbonImmutable::parse($this->customStart),
                CarbonImmutable::parse($this->customEnd),
            );
        }

        return Period::fromKey($this->period);
    }

    /**
     * The locations this render covers — null means "All" (org rollup). Cross-location
     * and All views are refused (403) to anyone without reports.view.all.
     *
     * @return list<string>|null
     */
    protected function resolveLocationIds(): ?array
    {
        if (static::$orgWide) {
            return null;
        }

        $scope = $this->scope ?? $this->defaultScope();

        if ($scope === 'all' || $scope === null) {
            abort_unless($this->user()->can('reports.view.all'), 403);

            return null;
        }

        abort_unless(in_array($scope, $this->allowedLocationIds(), true), 403);

        return [$scope];
    }

    protected function organisationId(): string
    {
        return (string) app(ActiveScope::class)->organisationId();
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    protected function canSeeAll(): bool
    {
        return static::$orgWide || $this->user()->can('reports.view.all');
    }

    /** @return list<string> */
    protected function allowedLocationIds(): array
    {
        if ($this->canSeeAll()) {
            return Location::query()->withoutGlobalScopes()
                ->where('organisation_id', $this->organisationId())->pluck('id')->all();
        }

        return $this->user()->locations()->pluck('locations.id')->all();
    }

    /**
     * @return array<string, string>
     */
    protected function scopeOptions(): array
    {
        $locations = Location::query()->withoutGlobalScopes()
            ->whereIn('id', $this->allowedLocationIds())->orderBy('name')->pluck('name', 'id')->all();

        return $this->canSeeAll()
            ? ['all' => __('Todas las sedes')] + $locations
            : $locations;
    }

    protected function defaultScope(): ?string
    {
        if ($this->canSeeAll()) {
            return 'all';
        }

        $active = app(ActiveScope::class)->locationId();
        $allowed = $this->allowedLocationIds();

        if ($active !== null && in_array($active, $allowed, true)) {
            return $active;
        }

        return $allowed[0] ?? null;
    }

    protected function scopeLabel(): string
    {
        $scope = $this->scope ?? $this->defaultScope();

        if ($scope === 'all' || $scope === null) {
            return __('Todas las sedes');
        }

        return (string) (Location::query()->withoutGlobalScopes()->whereKey($scope)->value('name') ?? __('Sede'));
    }

    protected function periodLabel(): string
    {
        $period = $this->resolvePeriod();
        $from = $period->start->translatedFormat('d/m/Y');
        $to = $period->end->subDay()->translatedFormat('d/m/Y');

        return $from === $to ? $from : "{$from} – {$to}";
    }
}
