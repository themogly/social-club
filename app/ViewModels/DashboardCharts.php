<?php

namespace App\ViewModels;

use App\Enums\BatchStatus;
use App\Enums\DispensationStatus;
use App\Enums\MembershipStatus;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Location;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Footfall;
use App\Support\Occupancy;
use App\Support\Period;
use App\Support\Settings;
use App\Support\StockCeiling;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's CHART + TABLE aggregates — the trend series, the split charts,
 * the per-genetic breakdowns, the footfall heatmap, the recent-transaction feed and
 * the owner's per-location comparison. Its sibling `Dashboard` owns the stat-card
 * figures + alerts; this class owns everything that is a time series or a breakdown,
 * so the page can stay thin. Every figure is a LIVE aggregate (transactional data is
 * never cached), scoped to the organisation, the locations the actor may see and the
 * selected period. Money is integer cents, weight integer centigrams — grams/euros
 * only appear where a chart axis needs a number (the display edge).
 */
class DashboardCharts
{
    /** @var list<string>|null memoised resolved location ids (null before first resolve) */
    private ?array $resolved = null;

    /**
     * @param  list<string>|null  $locationIds  null = every location in the org (owner rollup)
     */
    public function __construct(
        public readonly string $organisationId,
        public readonly ?array $locationIds,
        public readonly Period $period,
        public readonly bool $canSeeFinance = true,
        public readonly bool $isRollup = false,
    ) {}

    public static function for(User $user, Period $period): self
    {
        $scope = app(ActiveScope::class);
        $activeLocation = $scope->locationId();
        $rollup = $user->hasRole(Role::OWNER->value) && $activeLocation === null;

        return new self(
            organisationId: (string) $scope->organisationId(),
            locationIds: $rollup ? null : [$activeLocation ?? ''],
            period: $period,
            canSeeFinance: $user->canAny(['reports.view', 'reports.view.all']),
            isRollup: $rollup,
        );
    }

    // --- Trend series (sparklines) --------------------------------------------------

    /** @return list<int> daily contribution cents over the trailing window */
    public function contributionsSeries(int $days = 14): array
    {
        [$start, $end, $bounds] = $this->trailingDays($days);
        $rows = DB::table('dispensations')->whereIn('location_id', $this->resolvedLocationIds())
            ->where('status', DispensationStatus::COMPLETED->value)
            ->whereBetween('dispensed_at', [$start, $end])->get(['dispensed_at', 'total_cents']);

        return $this->bucket($rows, $bounds, 'dispensed_at', 'total_cents');
    }

    /** @return list<int> daily grams (centigrams) dispensed over the trailing window */
    public function gramsSeries(int $days = 14): array
    {
        [$start, $end, $bounds] = $this->trailingDays($days);
        $rows = DB::table('dispensation_lines')
            ->join('dispensations', 'dispensation_lines.dispensation_id', '=', 'dispensations.id')
            ->whereIn('dispensations.location_id', $this->resolvedLocationIds())
            ->where('dispensations.status', DispensationStatus::COMPLETED->value)
            ->whereBetween('dispensations.dispensed_at', [$start, $end])
            ->get(['dispensations.dispensed_at as dispensed_at', 'dispensation_lines.grams_cg as grams_cg']);

        return $this->bucket($rows, $bounds, 'dispensed_at', 'grams_cg');
    }

    /** @return list<int> daily transaction count (dispensations + bar orders) over the trailing window */
    public function transactionsSeries(int $days = 14): array
    {
        [$start, $end, $bounds] = $this->trailingDays($days);
        $ids = $this->resolvedLocationIds();
        $disp = DB::table('dispensations')->whereIn('location_id', $ids)
            ->where('status', DispensationStatus::COMPLETED->value)
            ->whereBetween('dispensed_at', [$start, $end])->get(['dispensed_at']);
        $ord = DB::table('orders')->whereIn('location_id', $ids)
            ->where('status', OrderStatus::COMPLETED->value)
            ->whereBetween('created_at', [$start, $end])->get(['created_at']);

        $a = $this->bucket($disp, $bounds, 'dispensed_at', null);
        $b = $this->bucket($ord, $bounds, 'created_at', null);

        return array_map(static fn (int $x, int $y): int => $x + $y, $a, $b);
    }

    /** @return list<int> daily check-in count over the trailing window */
    public function checkInsSeries(int $days = 14): array
    {
        [$start, $end, $bounds] = $this->trailingDays($days);
        $rows = DB::table('check_ins')->whereIn('location_id', $this->resolvedLocationIds())
            ->whereBetween('checked_in_at', [$start, $end])->get(['checked_in_at']);

        return $this->bucket($rows, $bounds, 'checked_in_at', null);
    }

    /** @return list<int> daily new-member count over the trailing window */
    public function newMembersSeries(int $days = 14): array
    {
        [$start, $end, $bounds] = $this->trailingDays($days);
        $rows = DB::table('members')->where('organisation_id', $this->organisationId)
            ->whereBetween('joined_at', [$start, $end])->get(['joined_at']);

        return $this->bucket($rows, $bounds, 'joined_at', null);
    }

    /** @return list<int> daily till variance cents over the trailing window (closed sessions) */
    public function varianceSeries(int $days = 14): array
    {
        [$start, $end, $bounds] = $this->trailingDays($days);
        $rows = DB::table('till_sessions')->whereIn('location_id', $this->resolvedLocationIds())
            ->whereNotNull('variance_cents')->whereNotNull('closed_at')
            ->whereBetween('closed_at', [$start, $end])->get(['closed_at', 'variance_cents']);

        return $this->bucket($rows, $bounds, 'closed_at', 'variance_cents');
    }

    // --- Income charts (split, never merged) ----------------------------------------

    /**
     * Income for the selected period bucketed by hour (a single day) or day (week/month/
     * custom), kept as three separate streams — aportaciones (cannabis), barra (bar/merch)
     * and cuotas (membership fees). Never merged into one "revenue" figure.
     *
     * @return array{labels: list<string>, aportaciones: list<int>, barra: list<int>, cuotas: list<int>}
     */
    public function incomeByPeriod(?Period $period = null): array
    {
        // Finance authorisation lives here too, not only on the widget (audit A1): a non-finance
        // actor (STAFF) gets empty series regardless of who calls this, so a forgotten blade @if
        // or a direct call can never leak income figures.
        if (! $this->canSeeFinance) {
            return ['labels' => [], 'aportaciones' => [], 'barra' => [], 'cuotas' => []];
        }

        $period ??= $this->period;
        [$start, $end] = $period->bounds();
        [$labels, $bounds, $unit] = $this->periodBuckets($period);
        $ids = $this->resolvedLocationIds();

        $disp = DB::table('dispensations')->whereIn('location_id', $ids)
            ->where('status', DispensationStatus::COMPLETED->value)
            ->whereBetween('dispensed_at', [$start, $end])->get(['dispensed_at', 'total_cents']);
        $ord = DB::table('orders')->whereIn('location_id', $ids)
            ->where('status', OrderStatus::COMPLETED->value)
            ->whereBetween('created_at', [$start, $end])->get(['created_at', 'total_cents']);
        $fees = DB::table('membership_fee_payments')
            ->join('memberships', 'membership_fee_payments.membership_id', '=', 'memberships.id')
            ->whereIn('memberships.location_id', $ids)
            ->whereBetween('membership_fee_payments.paid_at', [$start, $end])
            ->get(['membership_fee_payments.paid_at as paid_at', 'membership_fee_payments.amount_cents as amount_cents']);

        return [
            'labels' => $labels,
            'aportaciones' => $this->bucket($disp, $bounds, 'dispensed_at', 'total_cents', $unit),
            'barra' => $this->bucket($ord, $bounds, 'created_at', 'total_cents', $unit),
            'cuotas' => $this->bucket($fees, $bounds, 'paid_at', 'amount_cents', $unit),
        ];
    }

    /**
     * Income vs expenses for the period, with the superávit (surplus) as the difference.
     *
     * @return array{labels: list<string>, ingresos: list<int>, gastos: list<int>, superavit: list<int>}
     */
    public function incomeVsExpenses(?Period $period = null): array
    {
        if (! $this->canSeeFinance) {
            return ['labels' => [], 'ingresos' => [], 'gastos' => [], 'superavit' => []];
        }

        $period ??= $this->period;
        $income = $this->incomeByPeriod($period);
        [$labels, $bounds, $unit] = $this->periodBuckets($period);
        [$start, $end] = $period->bounds();

        $expenseRows = DB::table('expenses')->whereIn('location_id', $this->resolvedLocationIds())
            ->whereNull('recurrence') // concrete outgoings only — a template is a schedule
            ->where('incurred_on', '>=', $start->toDateString())
            ->where('incurred_on', '<', $end->toDateString())
            ->get(['incurred_on', 'amount_cents']);
        $gastos = $this->bucket($expenseRows, $bounds, 'incurred_on', 'amount_cents', $unit);

        $ingresos = [];
        $superavit = [];
        foreach ($labels as $i => $_label) {
            $in = $income['aportaciones'][$i] + $income['barra'][$i] + $income['cuotas'][$i];
            $ingresos[] = $in;
            $superavit[] = $in - $gastos[$i];
        }

        return ['labels' => $labels, 'ingresos' => $ingresos, 'gastos' => $gastos, 'superavit' => $superavit];
    }

    // --- Breakdowns -----------------------------------------------------------------

    /**
     * Top genetics by grams dispensed in the period — powers both the horizontal bar
     * chart and the "top dispensado" table (genetic · tx · grams · total).
     *
     * @return list<array{genetic: string, tx: int, grams_cg: int, total_cents: int}>
     */
    public function dispensedByGenetic(int $limit = 10, ?Period $period = null): array
    {
        $period ??= $this->period;
        [$start, $end] = $period->bounds();

        return DB::table('dispensation_lines')
            ->join('dispensations', 'dispensation_lines.dispensation_id', '=', 'dispensations.id')
            ->whereIn('dispensations.location_id', $this->resolvedLocationIds())
            ->where('dispensations.status', DispensationStatus::COMPLETED->value)
            ->whereBetween('dispensations.dispensed_at', [$start, $end])
            ->groupBy('dispensation_lines.genetic_id')
            ->selectRaw('MAX(dispensation_lines.genetic_name_snapshot) as genetic')
            ->selectRaw('COUNT(DISTINCT dispensation_lines.dispensation_id) as tx')
            ->selectRaw('SUM(dispensation_lines.grams_cg) as grams_cg')
            ->selectRaw('SUM(dispensation_lines.line_total_cents) as total_cents')
            ->orderByDesc('grams_cg')
            ->limit($limit)->get()
            ->map(fn (\stdClass $r): array => [
                'genetic' => (string) ($r->genetic ?? __('Sin genética')),
                'tx' => (int) $r->tx,
                'grams_cg' => (int) $r->grams_cg,
                // The € value is a finance figure — zeroed for a non-finance actor so the chart's
                // "Importe" mode can't leak per-genetic revenue while grams stay visible (audit A1).
                'total_cents' => $this->canSeeFinance ? (int) $r->total_cents : 0,
            ])->all();
    }

    /**
     * Distribution of active members by how much of their monthly limit they have used
     * this month — a compliance/behaviour histogram. The denominator is the member's own
     * cap where set, otherwise the org default. Two grouped queries, never per-member.
     *
     * @return array{labels: list<string>, counts: list<int>}
     */
    public function consumptionDistribution(): array
    {
        $labels = ['0–25%', '25–50%', '50–75%', '75–100%', '>100%'];
        $counts = [0, 0, 0, 0, 0];

        $members = DB::table('members')
            ->join('memberships', 'members.id', '=', 'memberships.member_id')
            ->where('members.organisation_id', $this->organisationId)
            ->whereIn('memberships.location_id', $this->resolvedLocationIds())
            ->where('memberships.status', MembershipStatus::ACTIVE->value)
            ->distinct()->get(['members.id as id', 'members.monthly_limit_cg as monthly_limit_cg']);

        if ($members->isEmpty()) {
            return ['labels' => $labels, 'counts' => $counts];
        }

        [$start, $end] = Period::thisMonth()->bounds();
        $used = DB::table('dispensation_lines')
            ->join('dispensations', 'dispensation_lines.dispensation_id', '=', 'dispensations.id')
            ->whereIn('dispensations.location_id', $this->resolvedLocationIds())
            ->where('dispensations.status', DispensationStatus::COMPLETED->value)
            ->whereBetween('dispensations.dispensed_at', [$start, $end])
            ->groupBy('dispensations.member_id')
            ->selectRaw('dispensations.member_id as member_id, SUM(dispensation_lines.grams_cg) as used')
            ->pluck('used', 'member_id');

        $default = max(1, (int) Settings::get('monthly_limit_cg', 10000));

        foreach ($members as $member) {
            $limit = ((int) ($member->monthly_limit_cg ?? 0)) ?: $default;
            $usedCg = (int) ($used[$member->id] ?? 0);
            $pct = $usedCg / $limit;
            $counts[$this->distributionBucket($pct)]++;
        }

        return ['labels' => $labels, 'counts' => $counts];
    }

    private function distributionBucket(float $pct): int
    {
        return match (true) {
            $pct <= 0.25 => 0,
            $pct <= 0.50 => 1,
            $pct <= 0.75 => 2,
            $pct <= 1.00 => 3,
            default => 4,
        };
    }

    /**
     * On-hand grams by genetic (open batches in scope) against the premises stock
     * ceiling — a compliance overlay. Grams (not centigrams) because a chart axis is
     * a display edge; the ceiling is the sum of the scoped locations' ceilings.
     *
     * @return array{labels: list<string>, grams: list<float>, ceiling_grams: float}
     */
    public function stockLevelsByGenetic(int $limit = 12): array
    {
        $rows = DB::table('batches')
            ->join('genetics', 'batches.genetic_id', '=', 'genetics.id')
            ->whereIn('batches.location_id', $this->resolvedLocationIds())
            ->where('batches.status', BatchStatus::OPEN->value)
            ->groupBy('batches.genetic_id')
            ->selectRaw("MAX(genetics.name) as genetic, COALESCE(SUM(CASE WHEN genetics.unit_type = 'UNIT' THEN batches.remaining_units * genetics.grams_per_unit_cg ELSE batches.remaining_cg END), 0) as remaining_cg")
            ->orderByDesc('remaining_cg')
            ->limit($limit)->get();

        $ceilingCg = $this->scopedLocations()->reduce(
            fn (int $carry, Location $l): int => $carry + StockCeiling::forLocation($l)['ceiling_cg'], 0,
        );

        return [
            'labels' => $rows->map(fn (\stdClass $r): string => (string) $r->genetic)->all(),
            'grams' => $rows->map(fn (\stdClass $r): float => (int) $r->remaining_cg / 100)->all(),
            'ceiling_grams' => $ceilingCg / 100,
        ];
    }

    /**
     * Footfall (check-ins) by weekday × hour across the period, summed over every scoped
     * location — powers the heatmap.
     *
     * @return array{matrix: list<list<int>>, max: int}
     */
    public function footfallHeatmap(?Period $period = null): array
    {
        $period ??= $this->period;
        [$start, $end] = $period->bounds();

        $matrix = array_fill(0, 7, array_fill(0, 24, 0));
        foreach ($this->scopedLocations() as $location) {
            foreach (Footfall::byHourAndWeekday($location, $start, $end) as $weekday => $hours) {
                foreach ($hours as $hour => $count) {
                    $matrix[$weekday][$hour] += $count;
                }
            }
        }

        $max = 0;
        foreach ($matrix as $hours) {
            $max = max($max, max($hours));
        }

        return ['matrix' => $matrix, 'max' => $max];
    }

    // --- Recent activity + owner rollup ---------------------------------------------

    /**
     * The latest dispensations and bar orders in scope for the period, merged and sorted
     * newest-first, with operator + member resolved in two batched lookups (no N+1).
     *
     * @return list<array{type: string, ref: string, member: ?string, operator: ?string, amount_cents: int, at: string}>
     */
    public function recentTransactions(int $limit = 8, ?Period $period = null): array
    {
        $period ??= $this->period;
        [$start, $end] = $period->bounds();
        $ids = $this->resolvedLocationIds();

        $disp = DB::table('dispensations')->whereIn('location_id', $ids)
            ->where('status', DispensationStatus::COMPLETED->value)
            ->whereBetween('dispensed_at', [$start, $end])
            ->orderByDesc('dispensed_at')->limit($limit)
            ->get(['id', 'member_id', 'operator_id', 'total_cents', 'dispensed_at'])
            ->map(fn (\stdClass $r): array => [
                'type' => 'dispensacion', 'member_id' => $r->member_id, 'operator_id' => $r->operator_id,
                'amount_cents' => (int) $r->total_cents, 'at' => (string) $r->dispensed_at,
            ]);
        $ord = DB::table('orders')->whereIn('location_id', $ids)
            ->where('status', OrderStatus::COMPLETED->value)
            ->whereBetween('created_at', [$start, $end])
            ->orderByDesc('created_at')->limit($limit)
            ->get(['id', 'member_id', 'operator_id', 'total_cents', 'created_at'])
            ->map(fn (\stdClass $r): array => [
                'type' => 'barra', 'member_id' => $r->member_id, 'operator_id' => $r->operator_id,
                'amount_cents' => (int) $r->total_cents, 'at' => (string) $r->created_at,
            ]);

        /** @var Collection<int, array{type: string, member_id: ?string, operator_id: ?string, amount_cents: int, at: string}> $merged */
        $merged = $disp->concat($ord)->sortByDesc('at')->take($limit)->values();

        $operators = DB::table('users')->whereIn('id', $merged->pluck('operator_id')->filter()->all())
            ->pluck('name', 'id');
        $members = DB::table('members')->whereIn('id', $merged->pluck('member_id')->filter()->all())
            ->pluck('member_no', 'id');

        return $merged->map(fn (array $row): array => [
            'type' => $row['type'],
            'ref' => $row['type'] === 'barra' ? __('Barra y tienda') : __('Dispensación'),
            'member' => $row['member_id'] !== null ? ($members[$row['member_id']] ?? null) : null,
            'operator' => $row['operator_id'] !== null ? ($operators[$row['operator_id']] ?? null) : null,
            'amount_cents' => $row['amount_cents'],
            'at' => $row['at'],
        ])->all();
    }

    /**
     * Per-location comparison for the owner's org-wide rollup — one row per location with
     * the period's contributions, grams and current occupancy. Empty for a non-rollup view.
     *
     * @return list<array{location: string, contributions_cents: int, grams_cg: int, inside: int}>
     */
    public function perLocationComparison(?Period $period = null): array
    {
        if (! $this->isRollup) {
            return [];
        }

        $period ??= $this->period;
        [$start, $end] = $period->bounds();

        return $this->scopedLocations()->map(function (Location $location) use ($start, $end): array {
            $contributions = (int) DB::table('dispensations')->where('location_id', $location->id)
                ->where('status', DispensationStatus::COMPLETED->value)
                ->whereBetween('dispensed_at', [$start, $end])->sum('total_cents');
            $grams = (int) DB::table('dispensation_lines')
                ->join('dispensations', 'dispensation_lines.dispensation_id', '=', 'dispensations.id')
                ->where('dispensations.location_id', $location->id)
                ->where('dispensations.status', DispensationStatus::COMPLETED->value)
                ->whereBetween('dispensations.dispensed_at', [$start, $end])->sum('dispensation_lines.grams_cg');
            $inside = (int) DB::table('check_ins')->where('location_id', $location->id)
                ->whereNull('checked_out_at')->count();

            return [
                'location' => $location->name,
                'contributions_cents' => $contributions,
                'grams_cg' => $grams,
                'inside' => $inside,
            ];
        })->all();
    }

    /**
     * Live occupancy (aforo) across the scoped locations — current heads, total capacity
     * and the fraction used, for the progress ring. Capacity is null when no scoped
     * location has one set (so the ring degrades to a plain count).
     *
     * @return array{inside: int, capacity: ?int, fraction: ?float}
     */
    public function occupancy(): array
    {
        $inside = 0;
        $capacity = 0;
        $hasCapacity = false;

        foreach ($this->scopedLocations() as $location) {
            $inside += Occupancy::current($location);
            if ($location->capacity !== null) {
                $capacity += $location->capacity;
                $hasCapacity = true;
            }
        }

        $cap = $hasCapacity ? $capacity : null;

        return [
            'inside' => $inside,
            'capacity' => $cap,
            'fraction' => ($cap !== null && $cap > 0) ? $inside / $cap : null,
        ];
    }

    // --- Scoping + bucketing helpers ------------------------------------------------

    /** @return list<string> */
    private function resolvedLocationIds(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        return $this->resolved = $this->locationIds ?? Location::query()->withoutGlobalScopes()
            ->where('organisation_id', $this->organisationId)->pluck('id')->all();
    }

    /**
     * @return Collection<int, Location>
     */
    private function scopedLocations(): Collection
    {
        return Location::query()->withoutGlobalScopes()
            ->whereIn('id', $this->resolvedLocationIds())->get();
    }

    /**
     * Half-open daily buckets for the trailing N days ending at the end of today.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: list<array{0: CarbonImmutable, 1: CarbonImmutable}>}
     */
    private function trailingDays(int $days): array
    {
        $tz = config('app.timezone') ?: 'UTC';
        $end = CarbonImmutable::now($tz)->startOfDay()->addDay();
        $start = $end->subDays($days);

        $bounds = [];
        $cursor = $start;
        while ($cursor < $end) {
            $next = $cursor->addDay();
            $bounds[] = [$cursor, $next];
            $cursor = $next;
        }

        return [$start, $end, $bounds];
    }

    /**
     * Buckets + labels + unit for the selected period: hourly across a single day,
     * otherwise one bucket per day.
     *
     * @return array{0: list<string>, 1: list<array{0: CarbonImmutable, 1: CarbonImmutable}>, 2: string}
     */
    private function periodBuckets(Period $period): array
    {
        [$start, $end] = $period->bounds();
        $labels = [];
        $bounds = [];

        if ($period->type === 'day') {
            $cursor = $start;
            while ($cursor < $end) {
                $bounds[] = [$cursor, $cursor->addHour()];
                $labels[] = $cursor->format('H:00');
                $cursor = $cursor->addHour();
            }

            return [$labels, $bounds, 'hour'];
        }

        $cursor = $start;
        while ($cursor < $end) {
            $next = $cursor->addDay();
            $bounds[] = [$cursor, $next];
            $labels[] = $cursor->format('j M');
            $cursor = $next;
        }

        return [$labels, $bounds, 'day'];
    }

    /**
     * Sum (or count when $valueKey is null) a set of raw rows into a fixed set of
     * time buckets, entirely in PHP so it is identical on SQLite and MySQL (no
     * driver-specific date functions). Bucket index is integer time-division from the
     * first bucket's start, so it is unaffected by gaps.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $bounds
     * @return list<int>
     */
    private function bucket(Collection $rows, array $bounds, string $timeKey, ?string $valueKey, string $unit = 'day'): array
    {
        $series = array_fill(0, count($bounds), 0);
        if ($bounds === []) {
            return $series;
        }

        $startTs = $bounds[0][0]->getTimestamp();
        $divisor = $unit === 'hour' ? 3600 : 86400;
        $count = count($bounds);

        foreach ($rows as $row) {
            $data = (array) $row;
            $ts = CarbonImmutable::parse((string) $data[$timeKey])->getTimestamp();
            $idx = intdiv($ts - $startTs, $divisor);
            if ($idx >= 0 && $idx < $count) {
                $series[$idx] += $valueKey === null ? 1 : (int) ($data[$valueKey] ?? 0);
            }
        }

        return $series;
    }
}
