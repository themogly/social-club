<?php

namespace App\ViewModels\Reports;

use App\Models\Location;
use App\Support\Period;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Base for every Informes report. Owns the scoping every report shares — organisation,
 * the locations the actor may see (null = the owner's "All" rollup) and the selected
 * period — plus the day-bucketing used by the takings series. Every figure is a LIVE
 * aggregate (transactional data is never cached); subclasses only add SQL.
 *
 * `build()` returns the report's tables, primary (sortable + exportable) first. Money
 * stays integer cents and weight integer centigrams right up to the ReportColumn edge.
 */
abstract class AbstractReport
{
    /** @var list<ReportTable>|null memoised tables (built once per request) */
    private ?array $tablesCache = null;

    /** @var list<string>|null memoised resolved location ids */
    private ?array $resolved = null;

    /**
     * @param  list<string>|null  $locationIds  null = every location in the org (owner rollup / All)
     */
    public function __construct(
        public readonly string $organisationId,
        public readonly ?array $locationIds,
        public readonly Period $period,
    ) {}

    abstract public function key(): string;

    abstract public function title(): string;

    /**
     * @return list<ReportTable> primary/exportable table first
     */
    abstract protected function build(): array;

    /**
     * @return list<ReportTable>
     */
    public function tables(): array
    {
        return $this->tablesCache ??= $this->build();
    }

    public function primary(): ReportTable
    {
        return $this->tables()[0];
    }

    /**
     * Headline figures for the summary strip (already formatted at the edge).
     *
     * @return list<array{label: string, value: string, tone?: string}>
     */
    public function summary(): array
    {
        return [];
    }

    /** True when the whole report has no rows anywhere — drives the designed empty state. */
    public function isEmpty(): bool
    {
        foreach ($this->tables() as $table) {
            if ($table->hasRows()) {
                return false;
            }
        }

        return true;
    }

    // --- Scoping --------------------------------------------------------------------

    /** @return list<string> */
    protected function resolvedLocationIds(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        return $this->resolved = $this->locationIds ?? Location::query()->withoutGlobalScopes()
            ->where('organisation_id', $this->organisationId)->pluck('id')->all();
    }

    /** True for the owner's "All locations" view — the only view that folds in org-wide (null-location) outgoings. */
    protected function includesAllLocations(): bool
    {
        return $this->locationIds === null;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function scopeByLocation(Builder $query, string $column = 'location_id'): Builder
    {
        return $query->whereIn($column, $this->resolvedLocationIds());
    }

    /**
     * @return Collection<int, Location>
     */
    protected function scopedLocations(): Collection
    {
        return Location::query()->withoutGlobalScopes()
            ->whereIn('id', $this->resolvedLocationIds())->orderBy('name')->get();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    protected function bounds(): array
    {
        return $this->period->bounds();
    }

    // --- Day bucketing (takings series) ---------------------------------------------

    /**
     * One [start, end) bucket per day across the period, with a human label. Bucketing
     * happens in PHP so it is identical on SQLite and MySQL (no date functions).
     *
     * @return array{labels: list<string>, keys: list<string>, bounds: list<array{0: CarbonImmutable, 1: CarbonImmutable}>}
     */
    protected function dayBuckets(): array
    {
        [$start, $end] = $this->bounds();
        $labels = [];
        $keys = [];
        $bounds = [];

        $cursor = $start;
        while ($cursor < $end) {
            $next = $cursor->addDay();
            $bounds[] = [$cursor, $next];
            $labels[] = $cursor->format('d/m/Y');
            $keys[] = $cursor->format('Y-m-d');
            $cursor = $next;
        }

        return ['labels' => $labels, 'keys' => $keys, 'bounds' => $bounds];
    }

    /**
     * Sum a set of raw rows into the day buckets — pure integer division from the first
     * bucket's start (unaffected by gaps or timezone), identical across drivers.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $bounds
     * @return list<int>
     */
    protected function bucketSum(Collection $rows, array $bounds, string $timeKey, string $valueKey): array
    {
        $series = array_fill(0, count($bounds), 0);
        if ($bounds === []) {
            return $series;
        }

        $startTs = $bounds[0][0]->getTimestamp();
        $count = count($bounds);

        foreach ($rows as $row) {
            $data = (array) $row;
            $ts = CarbonImmutable::parse((string) $data[$timeKey])->getTimestamp();
            $idx = intdiv($ts - $startTs, 86400);
            if ($idx >= 0 && $idx < $count) {
                $series[$idx] += (int) ($data[$valueKey] ?? 0);
            }
        }

        return $series;
    }
}
