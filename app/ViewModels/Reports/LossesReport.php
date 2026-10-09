<?php

namespace App\ViewModels\Reports;

use App\Enums\CashPot;
use App\Enums\DiscountKind;
use App\Enums\DispensationStatus;
use App\Enums\OrderStatus;
use App\Enums\StockMovementType;
use App\Enums\TillSessionStatus;
use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Resources\Dispensations\DispensationResource;
use App\Filament\Resources\Members\MemberResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\TillSessions\TillSessionResource;
use App\Models\Batch;
use App\Models\Location;
use App\Models\StockTake;
use App\Models\TillSession;
use App\Models\User;
use App\Support\Money;
use App\Support\NumberFormat;
use App\Support\Period;
use App\Support\Reports\GivenAwayQueries;
use App\Support\Settings;
use App\Support\Spreadsheet\ReportExport;
use App\Support\Weight;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Prompt 367 — *Pérdidas*: everything that cost the club money in the period, in one place, with who and why.
 *
 * It stores nothing: every figure is read from the records that already hold it, through the SAME queries as the report that
 * showed it first where one exists ({@see GivenAwayQueries}: adjustments and waived fees, as *Descuentos y ajustes*). Five
 * sections, each a list of events; an event's `cents` is SIGNED — positive is money lost, negative an offset (a price raised,
 * rounding taken, grams charged over the weight, a stock gain, a till surplus):
 *
 *  1. mostrador — adjustments down / up (recovered), whole-euro rounding net, waived fees; member discounts are listed here
 *     as INFORMATION (agreed policy, not a loss) — beside the headline, never in it, the staff member's own apart;
 *  2. peso — the half-gram rounding (355): weighed − charged on each weight line, at that line's own price;
 *  3. existencias — merma, adjustments down (*Ajuste*, *Actualizar peso*), count shortfalls (end-of-day weigh, *Inventario*),
 *     gains as an offset; valued AT COST (the total) and at the batch's contribution price (shown). Moves inside a batch
 *     (*Rellenar*, *Rellenado sin registrar*) are RESERVE_* rows and never read; a movement with a `reference` is a void's or a
 *     refund's stock coming back (or the refund's merma) and is counted once, as the refund or the void;
 *  4. caja — every counted pot's difference at the close (sessions opened in the period, as *Informes → Cajas*), «Sin
 *     explicar» when 366 says so;
 *  5. devoluciones — refunds, and voided dispensations and bar orders that no fresh row corrects (a void + a new linked row
 *     is the canon for a correction, not a loss).
 *
 * **No SQL arithmetic on an unsigned column** (370): `charged_cg`, `original_total_cents` and friends are read as they are
 * and subtracted in PHP.
 *
 * @phpstan-type Event array{section: string, line: string, at: string, location_id: string, operator_id: ?string, member_id: ?string, cents: int, grams: int, contribution: ?int, reason: ?string, what: string, url: ?string, flag: ?string, no_cost: bool}
 * @phpstan-type Line array{label: string, cents: int, grams: int, contribution: int, count: int, info: bool, no_cost: int}
 */
class LossesReport extends AbstractReport
{
    public const DETAIL_PER_PAGE = 50;

    /** Past this many days the chart groups by week (as the staff-hours chart does). */
    public const DAILY_CHART_MAX_DAYS = 62;

    /** The default «Avisar si las pérdidas de un día superan el … %» (per sede). */
    public const DEFAULT_ALERT_PCT = 5;

    private ?string $sectionFilter = null;

    private ?string $personFilter = null;

    private int $detailPage = 1;

    /** @var (Closure(array<string, string|null>): string)|null builds a link to this report with the given detail filters */
    private ?Closure $linker = null;

    /** @var array{events: list<Event>, takings: array<string, int>, takings_by_sede: array<string, int>, sales: list<array{at: string, location_id: string, operator_id: ?string, cents: int}>}|null */
    private ?array $data = null;

    public function key(): string
    {
        return 'perdidas';
    }

    public function title(): string
    {
        return __('Pérdidas');
    }

    /** @return array<string, string> section key => title, in reading order */
    public static function sectionTitles(): array
    {
        return [
            'mostrador' => __('Descuentos y regalos en el mostrador'),
            'peso' => __('Peso de más'),
            'existencias' => __('Existencias perdidas'),
            'caja' => __('Diferencias de caja'),
            'devoluciones' => __('Devoluciones y anulaciones'),
        ];
    }

    /** @return array<string, array<string, array{0: string, 1: bool}>> section => line => [label, information only] */
    private static function lineDefinitions(): array
    {
        return [
            'mostrador' => [
                // Prompt 375 — member discounts by the kind stored on each line from now on; the staff's own stays apart.
                'member_discounts_staff' => [__('Descuentos del personal (aparte)'), true],
                'member_discounts_local' => [__('Descuento :kind (aparte)', ['kind' => DiscountKind::LOCAL->label()]), true],
                'member_discounts_concession' => [__('Descuento :kind (aparte)', ['kind' => DiscountKind::CONCESSION->label()]), true],
                'member_discounts_therapeutic' => [__('Descuento :kind (aparte)', ['kind' => DiscountKind::THERAPEUTIC->label()]), true],
                'member_discounts_custom' => [__('Descuento :kind (aparte)', ['kind' => DiscountKind::CUSTOM->label()]), true],
                'member_discounts_tier' => [__('Descuento :kind (aparte)', ['kind' => DiscountKind::reportLabel('TIER')]), true],
                'member_discounts_unclassified' => [__('Descuentos sin clasificar, anteriores a hoy (aparte)'), true],
                'overrides_given' => [__('Ajustes de precio a la baja'), false],
                'overrides_recovered' => [__('Ajustes de precio al alza: recuperado'), false],
                'rounding' => [__('Redondeo al euro (neto)'), false],
                'waived_fees' => [__('Cuotas condonadas'), false],
            ],
            'peso' => [
                'weight_over' => [__('Entregado por encima de lo cobrado'), false],
                'weight_under' => [__('Cobrado por encima de lo pesado (compensa)'), false],
            ],
            'existencias' => [
                'merma' => [__('Merma'), false],
                'adjustments' => [__('Ajustes a la baja'), false],
                'count_till' => [__('Faltas en el pesaje de cierre'), false],
                'count_inventory' => [__('Faltas en el inventario'), false],
                'stock_gains' => [__('Ajustes y recuentos al alza (compensa)'), false],
            ],
            'caja' => [
                'till_short' => [__('Faltas de caja'), false],
                'till_over' => [__('Sobrantes de caja (compensa)'), false],
            ],
            'devoluciones' => [
                'refunds' => [__('Devoluciones'), false],
                'voided_dispensations' => [__('Dispensaciones anuladas'), false],
                'voided_orders' => [__('Barra y tienda: anulaciones'), false],
            ],
        ];
    }

    /** Nothing lost and nothing taken: the designed empty state (the sections table always has its lines, so it cannot decide). */
    public function isEmpty(): bool
    {
        return $this->data()['events'] === [] && $this->data()['takings'] === [];
    }

    /** The detail's filters and page (the page's URL state). */
    public function withDetail(?string $section, ?string $person, int $page): static
    {
        $this->sectionFilter = array_key_exists((string) $section, self::sectionTitles()) ? $section : null;
        $this->personFilter = filled($person) ? $person : null;
        $this->detailPage = max(1, $page);

        return $this;
    }

    /** @param  Closure(array<string, string|null>): string  $linker */
    public function linkingWith(Closure $linker): static
    {
        $this->linker = $linker;

        return $this;
    }

    /** @param  array<string, string|null>  $filters */
    private function link(array $filters): ?string
    {
        return $this->linker !== null ? ($this->linker)($filters) : null;
    }

    // --- Figures -------------------------------------------------------------------------------------------------------

    /**
     * Each section with its lines and its total (information lines are not in the total).
     *
     * @return array<string, array{label: string, total: int, lines: array<string, Line>, no_cost: int, no_cost_url: ?string}>
     */
    public function sections(): array
    {
        $sections = [];
        foreach (self::lineDefinitions() as $section => $lines) {
            $sections[$section] = ['label' => self::sectionTitles()[$section], 'total' => 0, 'lines' => [], 'no_cost' => 0, 'no_cost_url' => null];
            foreach ($lines as $key => [$label, $info]) {
                $sections[$section]['lines'][$key] = ['label' => $label, 'cents' => 0, 'grams' => 0, 'contribution' => 0, 'count' => 0, 'info' => $info, 'no_cost' => 0];
            }
        }

        $noCostUrls = [];
        foreach ($this->data()['events'] as $e) {
            $line = &$sections[$e['section']]['lines'][$e['line']];
            $line['cents'] += $e['cents'];
            $line['grams'] += $e['grams'];
            $line['contribution'] += (int) $e['contribution'];
            $line['count']++;
            if ($e['no_cost']) {
                $line['no_cost']++;
                $sections[$e['section']]['no_cost']++;
                $noCostUrls[$e['section']][(string) $e['url']] = true;
            }
            if (! $line['info']) {
                $sections[$e['section']]['total'] += $e['cents'];
            }
            unset($line);
        }

        // Where to add the missing cost: the batch, when it is one; else the section's list.
        foreach ($noCostUrls as $section => $urls) {
            $sections[$section]['no_cost_url'] = count($urls) === 1 ? (string) array_key_first($urls) : $this->link(['section' => $section, 'person' => null]);
        }

        return $sections;
    }

    /** @return array{total: int, member_discounts: int, staff_discounts: int, takings: int, previous_total: int} */
    public function headline(): array
    {
        $sections = $this->sections();
        $counter = $sections['mostrador']['lines'];

        return [
            'total' => $this->total(),
            'member_discounts' => array_sum(array_map(fn (array $line): int => $line['info'] ? $line['cents'] : 0, $counter)),
            'staff_discounts' => $counter['member_discounts_staff']['cents'],
            'takings' => array_sum($this->data()['takings']),
            'previous_total' => (new self($this->organisationId, $this->locationIds, $this->period->previous()))->total(),
        ];
    }

    private function total(): int
    {
        return array_sum(array_map(fn (array $e): int => $this->counts($e) ? $e['cents'] : 0, $this->data()['events']));
    }

    /** @param  Event  $e */
    private function counts(array $e): bool
    {
        return ! self::lineDefinitions()[$e['section']][$e['line']][1];
    }

    public function summary(): array
    {
        $h = $this->headline();
        $diff = $h['total'] - $h['previous_total'];

        return [
            ['key' => 'total', 'label' => __('Total perdido'), 'value' => Money::fromCents($h['total'])->formatted(), 'tone' => $h['total'] > 0 ? 'warning' : 'success'],
            ['key' => 'share', 'label' => __('Sobre lo recaudado'), 'value' => __(':pct % de lo recaudado', ['pct' => self::share($h['total'], $h['takings'])])],
            ['key' => 'previous', 'label' => __('Comparado'), 'value' => ($diff < 0 ? '−' : '+').Money::fromCents(abs($diff))->formatted().' '.$this->comparedWith()],
            ['key' => 'member_discounts', 'label' => __('Descuentos de socio (aparte)'), 'value' => Money::fromCents($h['member_discounts'])->formatted()],
            ['key' => 'staff_discounts', 'label' => __('De ellos, al personal'), 'value' => Money::fromCents($h['staff_discounts'])->formatted()],
        ];
    }

    /** «4.2»: a loss as a share of takings, one decimal, a point (316). */
    public static function share(int $cents, int $takings): string
    {
        return NumberFormat::decimal($takings > 0 ? round($cents * 100 / $takings, 1) : 0, 1);
    }

    private function comparedWith(): string
    {
        [$start, $end] = $this->bounds();
        $days = (int) round($start->diffInHours($end) / 24);

        return match (true) {
            $this->period->type === 'month' => __('frente al mes anterior'),
            $days === 1 => __('frente al día anterior'),
            $days === 7 => __('frente a la semana anterior'),
            default => __('frente al período anterior'),
        };
    }

    /**
     * The headline per day (per week past {@see DAILY_CHART_MAX_DAYS} days) across the period, for the chart.
     *
     * @return array{labels: list<string>, values: list<int>, by_week: bool}
     */
    public function series(): array
    {
        $buckets = $this->dayBuckets();
        $byWeek = count($buckets['bounds']) > self::DAILY_CHART_MAX_DAYS;
        $values = array_fill(0, count($buckets['bounds']), 0);
        $first = $buckets['bounds'][0][0] ?? null;
        foreach ($this->data()['events'] as $e) {
            if ($first === null || ! $this->counts($e)) {
                continue;
            }
            $index = intdiv(CarbonImmutable::parse($e['at'])->getTimestamp() - $first->getTimestamp(), 86400);
            if ($index >= 0 && $index < count($values)) {
                $values[$index] += $e['cents'];
            }
        }

        // Each bar is named by its business day's start on the sede's wall clock (a day starting at 00:00 in Madrid is 22:00
        // the day before in storage time, 271).
        $label = fn (int $i, string $format): string => $buckets['bounds'][$i][0]->setTimezone(Period::displayTimezone())->translatedFormat($format);
        if (! $byWeek) {
            return ['labels' => array_map(fn (int $i): string => $label($i, 'D j'), array_keys($values)), 'values' => $values, 'by_week' => false];
        }

        $labels = [];
        $weekly = [];
        foreach (array_chunk($values, 7, true) as $chunk) {
            $labels[] = $label((int) array_key_first($chunk), 'j M');
            $weekly[] = array_sum($chunk);
        }

        return ['labels' => $labels, 'values' => $weekly, 'by_week' => true];
    }

    /**
     * One row per person (operator of record), a column per section, their total and their takings.
     *
     * @return list<array<string, mixed>>
     */
    public function byPerson(): array
    {
        /** One tally per person: operator id (or 'none') => [sections => cents, information => cents, takings]. */
        $tally = [];
        foreach ($this->data()['takings'] as $id => $cents) {
            $tally[$id] = $this->tally($tally[$id] ?? null);
            $tally[$id]['recaudado'] += $cents;
        }
        foreach ($this->data()['events'] as $e) {
            $id = $e['operator_id'] ?? 'none';
            $tally[$id] = $this->tally($tally[$id] ?? null);
            if ($this->counts($e)) {
                $tally[$id]['sections'][$e['section']] += $e['cents'];
            } else {
                $tally[$id]['info'] += $e['cents'];
            }
        }

        $rows = [];
        foreach ($tally as $id => $t) {
            $operatorId = $id === 'none' ? null : (string) $id;
            $total = array_sum($t['sections']);
            $rows[] = ['persona' => $this->name($operatorId), 'operator_id' => $operatorId, ...$t['sections'], 'total' => $total,
                'descuentos_socio' => $t['info'], 'recaudado' => $t['recaudado'], 'pct' => $t['recaudado'] > 0 ? (int) round($total * 100 / $t['recaudado']) : 0,
                'persona__url' => $operatorId !== null ? $this->link(['person' => $operatorId, 'section' => null]) : null];
        }

        return $rows;
    }

    /**
     * A person's or a sede's running tally (a fresh one when there is none yet).
     *
     * @param  array{sections: array<string, int>, info: int, recaudado: int}|null  $tally
     * @return array{sections: array<string, int>, info: int, recaudado: int}
     */
    private function tally(?array $tally): array
    {
        return $tally ?? ['sections' => array_fill_keys(array_keys(self::sectionTitles()), 0), 'info' => 0, 'recaudado' => 0];
    }

    /**
     * The events, newest first, filtered to a section (and the detail's person filter), with names resolved.
     *
     * @return list<array<string, mixed>>
     */
    public function events(?string $section = null): array
    {
        $section ??= $this->sectionFilter;
        $events = array_values(array_filter($this->data()['events'], fn (array $e): bool => ($section === null || $e['section'] === $section)
            && ($this->personFilter === null || $e['operator_id'] === $this->personFilter)));
        usort($events, fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        $sedes = DB::table('locations')->whereIn('id', array_unique(array_column($events, 'location_id')))->pluck('name', 'id');
        $members = DB::table('members')->whereIn('id', array_filter(array_unique(array_column($events, 'member_id'))))->pluck('member_no', 'id');
        $definitions = self::lineDefinitions();

        return array_map(fn (array $e): array => array_filter([
            'fecha' => $e['at'],
            'fecha__url' => $e['url'],
            'sede' => (string) ($sedes[$e['location_id']] ?? '—'),
            'concepto' => $definitions[$e['section']][$e['line']][0],
            'que' => $e['what'] !== '' ? $e['what'] : '—',
            'persona' => $this->name($e['operator_id']),
            'socio' => $e['member_id'] !== null ? (string) ($members[$e['member_id']] ?? '—') : '—',
            'socio__url' => $e['member_id'] !== null ? MemberResource::getUrl('view', ['record' => $e['member_id']]) : null,
            'gramos' => $e['grams'],
            'importe' => $e['cents'],
            'importe__text' => $e['no_cost'] ? __('sin coste registrado') : null,
            'aportacion' => $e['contribution'] !== null ? Money::fromCents($e['contribution'])->formatted() : '—',
            'motivo' => trim(($e['flag'] !== null ? $e['flag'].' · ' : '').($e['reason'] ?? ''), ' ·') ?: '—',
            'motivo__tone' => $e['flag'] !== null ? 'warning' : null,
        ], fn ($v): bool => $v !== null), $events);
    }

    /** @return array{page: int, pages: int} */
    public function detailPages(): array
    {
        $pages = max(1, (int) ceil(count($this->events()) / self::DETAIL_PER_PAGE));

        return ['page' => min($this->detailPage, $pages), 'pages' => $pages];
    }

    /** @return array<string, string> operator id => name, for the detail's filter */
    public function personOptions(): array
    {
        $options = [];
        foreach ($this->byPerson() as $row) {
            if ($row['operator_id'] !== null) {
                $options[$row['operator_id']] = $row['persona'];
            }
        }
        asort($options);

        return $options;
    }

    // --- Yesterday (the dashboard line and the alert) ---------------------------------------------------------------------

    /**
     * Yesterday's business day at these sedes, measured by the day boundary of `$boundary` (else the sede in scope, 271).
     *
     * @param  list<string>  $locationIds
     */
    public static function forYesterday(string $organisationId, array $locationIds, ?Location $boundary = null): self
    {
        return new self($organisationId, $locationIds, Period::today($boundary ?? Period::sedeInScope())->previous());
    }

    /**
     * The headline and takings, for a line that says them — of the whole period, or (prompt 375) of a window inside it: the
     * dashboard reads yesterday out of its one 7-day report.
     *
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}|null  $within
     * @return array{total: int, takings: int, pct: string}
     */
    public function dayFigures(?array $within = null): array
    {
        $total = array_sum(array_map(fn (array $e): int => $this->counts($e) ? $e['cents'] : 0, $this->eventsWithin($within)));
        $takings = array_sum(array_column($this->salesWithin($within), 'cents'));

        return ['total' => $total, 'takings' => $takings, 'pct' => self::share($total, $takings)];
    }

    /**
     * The sedes whose losses went over their threshold (`losses_alert_threshold_pct`, default 5 %) of their takings — in the
     * period, or in a window inside it. A sede with no takings never alerts (there is no share to speak of). Never throws: an
     * alert must not break a dashboard or a run.
     *
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}|null  $within
     * @return array<string, array{total: int, takings: int, pct: string}> sede id => its figures
     */
    public function sedesAboveThreshold(?array $within = null): array
    {
        try {
            $totals = array_fill_keys($this->resolvedLocationIds(), 0);
            $takings = array_fill_keys($this->resolvedLocationIds(), 0);
            foreach ($this->eventsWithin($within) as $e) {
                if (isset($totals[$e['location_id']]) && $this->counts($e)) {
                    $totals[$e['location_id']] += $e['cents'];
                }
            }
            foreach ($this->salesWithin($within) as $sale) {
                if (isset($takings[$sale['location_id']])) {
                    $takings[$sale['location_id']] += $sale['cents'];
                }
            }

            $above = [];
            foreach ($totals as $sedeId => $total) {
                $threshold = max(0, (int) Settings::get('losses_alert_threshold_pct', self::DEFAULT_ALERT_PCT, (string) $sedeId));
                if ($takings[$sedeId] > 0 && $total * 100 > $threshold * $takings[$sedeId]) {
                    $above[(string) $sedeId] = ['total' => $total, 'takings' => $takings[$sedeId], 'pct' => self::share($total, $takings[$sedeId])];
                }
            }

            return $above;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}|null  $within
     * @return list<Event>
     */
    private function eventsWithin(?array $within): array
    {
        return $within === null ? $this->data()['events']
            : array_values(array_filter($this->data()['events'], fn (array $e): bool => self::inside($e['at'], $within)));
    }

    /**
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}|null  $within
     * @return list<array{at: string, location_id: string, operator_id: ?string, cents: int}>
     */
    private function salesWithin(?array $within): array
    {
        return $within === null ? $this->data()['sales']
            : array_values(array_filter($this->data()['sales'], fn (array $sale): bool => self::inside($sale['at'], $within)));
    }

    /** @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $within */
    private static function inside(string $at, array $within): bool
    {
        $ts = CarbonImmutable::parse($at)->getTimestamp();

        return $ts >= $within[0]->getTimestamp() && $ts < $within[1]->getTimestamp();
    }

    /**
     * Prompt 375 — the last 7 business days at a sede (today and the six before): the per-person signal's window, and what the
     * report opens on from its link (`?period=last7`).
     */
    public static function lastSevenDays(?Location $sede = null): Period
    {
        $today = Period::today($sede);

        return Period::custom($today->firstDay()->subDays(6), $today->firstDay(), $sede ?? Period::sedeInScope());
    }

    /**
     * Prompt 375 — 291's per-person signal, restored beside the sede's: the people whose losses over the last 7 days (every
     * section, as *Por persona* adds them) exceed the sede's threshold % of THEIR OWN takings, with at least
     * `losses_person_min_takings_cents` taken. One person giving away 16 % is visible even while the sede stays under 5 %.
     *
     * @return array{count: int, pct: string, period: Period} how many, and the highest share among them
     */
    public static function peopleAboveThreshold(Location $sede): array
    {
        $period = self::lastSevenDays($sede);
        $people = (new self((string) $sede->organisation_id, [(string) $sede->id], $period))->peopleAboveThresholdBySede()[(string) $sede->id] ?? ['count' => 0, 'pct' => '0'];

        return [...$people, 'period' => $period];
    }

    /**
     * The per-person signal for each sede of this report (its period should be the 7 days): each person's losses and takings
     * AT THAT SEDE, against that sede's threshold and floor. One report serves every sede the dashboard shows.
     *
     * @return array<string, array{count: int, pct: string}> sede id => how many, and the highest share among them
     */
    public function peopleAboveThresholdBySede(): array
    {
        try {
            $tally = []; // sede => operator => [lost, taken]
            foreach ($this->data()['events'] as $e) {
                if ($e['operator_id'] !== null && $this->counts($e)) {
                    $tally[$e['location_id']][$e['operator_id']][0] = ($tally[$e['location_id']][$e['operator_id']][0] ?? 0) + $e['cents'];
                }
            }
            foreach ($this->data()['sales'] as $sale) {
                if ($sale['operator_id'] !== null) {
                    $tally[$sale['location_id']][$sale['operator_id']][1] = ($tally[$sale['location_id']][$sale['operator_id']][1] ?? 0) + $sale['cents'];
                }
            }

            $out = [];
            foreach ($this->resolvedLocationIds() as $sedeId) {
                $threshold = max(0, (int) Settings::get('losses_alert_threshold_pct', self::DEFAULT_ALERT_PCT, (string) $sedeId));
                $floor = null; // read only when someone is over the threshold (the dashboard's query budget)
                $shares = [];
                foreach ($tally[$sedeId] ?? [] as $figures) {
                    [$lost, $taken] = [(int) ($figures[0] ?? 0), (int) ($figures[1] ?? 0)];
                    if ($taken > 0 && $lost * 100 > $threshold * $taken) {
                        $floor ??= max(0, (int) Settings::get('losses_person_min_takings_cents', 5000, (string) $sedeId));
                        if ($taken >= $floor) {
                            $shares[] = $lost * 100 / $taken;
                        }
                    }
                }
                $out[(string) $sedeId] = ['count' => count($shares), 'pct' => NumberFormat::decimal(round($shares === [] ? 0 : max($shares)), 0)];
            }

            return $out;
        } catch (Throwable) {
            return []; // an alert must never break a dashboard or a run
        }
    }

    /** @return array{total: int, takings: int, pct: string, period: Period} yesterday at one sede */
    public static function yesterday(Location $sede): array
    {
        $report = self::forYesterday((string) $sede->organisation_id, [(string) $sede->id], $sede);

        return [...$report->dayFigures(), 'period' => $report->period];
    }

    /** @return array{total: int, takings: int, pct: string, period: Period}|null yesterday at one sede, when it went over its threshold */
    public static function yesterdayAboveThreshold(Location $sede): ?array
    {
        $report = self::forYesterday((string) $sede->organisation_id, [(string) $sede->id], $sede);
        $above = $report->sedesAboveThreshold()[(string) $sede->id] ?? null;

        return $above !== null ? [...$above, 'period' => $report->period] : null;
    }

    // --- Export -----------------------------------------------------------------------------------------------------------

    /** The CSV: the headline, the sections and the people, one block each. */
    public function csv(): string
    {
        $headline = new ReportTable(
            key: 'headline',
            title: __('Pérdidas'),
            columns: [ReportColumn::text('concepto', __('Concepto')), ReportColumn::text('valor', __('Valor'))],
            rows: array_map(fn (array $chip): array => ['concepto' => $chip['label'], 'valor' => $chip['value']], $this->summary()),
        );
        $tables = collect($this->tables())->keyBy('key');

        return ReportExport::csvBlocks([$headline, $tables['sections'], $tables['by_person']]);
    }

    // --- Tables ---------------------------------------------------------------------------------------------------------

    protected function build(): array
    {
        $tables = [$this->byPersonTable(), $this->sectionsTable()];
        if ($this->includesAllLocations()) {
            $tables[] = $this->bySedeTable();
        }
        $tables[] = $this->detailTable();

        return $tables;
    }

    private function byPersonTable(): ReportTable
    {
        $rows = $this->byPerson();

        return new ReportTable(
            key: 'by_person',
            title: __('Por persona'),
            columns: [
                ReportColumn::text('persona', __('Persona')),
                ...$this->sectionColumns(),
                ReportColumn::money('total', __('Total perdido')),
                ReportColumn::money('recaudado', __('Recaudado')),
                ReportColumn::percent('pct', __('Perdido sobre lo recaudado')),
                ReportColumn::money('descuentos_socio', __('Descuentos de socio (aparte)')),
            ],
            rows: $rows,
            totals: $this->sumColumns($rows, ['mostrador', 'peso', 'existencias', 'caja', 'devoluciones', 'total', 'recaudado', 'descuentos_socio']),
            empty: __('Nada perdido en este período.'),
            emptyHint: __('Aquí aparecen los descuentos, ajustes, peso de más, mermas, diferencias de caja, devoluciones y anulaciones.'),
            defaultSort: 'total',
            sortable: true,
            note: __('Cada pérdida va a quien la hizo: el ajuste a quien lo autorizó, la condonación a quien la registró, la merma o el ajuste de existencias a quien lo anotó, la diferencia de caja a quien cerró, la devolución o la anulación a quien la hizo. Los descuentos de socio siguen al socio: se muestran aparte, fuera del total.'),
        );
    }

    /** @return list<ReportColumn> */
    private function sectionColumns(): array
    {
        return array_map(fn (string $key, string $title): ReportColumn => ReportColumn::money($key, $title), array_keys(self::sectionTitles()), self::sectionTitles());
    }

    private function sectionsTable(): ReportTable
    {
        $rows = [];
        foreach ($this->sections() as $key => $section) {
            foreach ($section['lines'] as $line) {
                $rows[] = [
                    'seccion' => $section['label'], 'seccion__url' => $this->link(['section' => $key, 'person' => null]),
                    'concepto' => $line['label'], 'movimientos' => $line['count'], 'gramos' => $line['grams'], 'importe' => $line['cents'],
                    'aportacion' => $key === 'existencias' ? Money::fromCents($line['contribution'])->formatted() : '—',
                ];
            }
            $rows[] = ['seccion' => $section['label'], 'concepto' => __('Total de la sección'), 'importe' => $section['total'], 'aportacion' => ''];
        }

        return new ReportTable(
            key: 'sections',
            title: __('Por sección'),
            columns: [
                ReportColumn::text('seccion', __('Sección'), sortable: false),
                ReportColumn::text('concepto', __('Concepto'), sortable: false),
                ReportColumn::number('movimientos', __('Movimientos'), sortable: false),
                ReportColumn::weight('gramos', __('Gramos'), sortable: false),
                ReportColumn::money('importe', __('Importe'), sortable: false),
                ReportColumn::text('aportacion', __('Al precio de aportación'), sortable: false),
            ],
            rows: $rows,
            totals: ['importe' => $this->total()],
            note: __('Las existencias cuentan a coste; al lado, lo que se habría aportado por ellas al precio del lote. Lo que compensa resta. Los descuentos de socio se muestran aparte y no suman.'),
        );
    }

    private function bySedeTable(): ReportTable
    {
        $tally = [];
        foreach ($this->scopedLocations() as $sede) {
            $tally[$sede->id] = $this->tally(null);
            $tally[$sede->id]['recaudado'] = (int) ($this->data()['takings_by_sede'][$sede->id] ?? 0);
        }
        foreach ($this->data()['events'] as $e) {
            if (isset($tally[$e['location_id']]) && $this->counts($e)) {
                $tally[$e['location_id']]['sections'][$e['section']] += $e['cents'];
            }
        }
        $names = $this->scopedLocations()->pluck('name', 'id');
        $rows = [];
        foreach ($tally as $id => $t) {
            $total = array_sum($t['sections']);
            $rows[] = ['sede' => (string) ($names[$id] ?? '—'), ...$t['sections'], 'total' => $total, 'recaudado' => $t['recaudado'],
                'pct' => $t['recaudado'] > 0 ? (int) round($total * 100 / $t['recaudado']) : 0];
        }

        return new ReportTable(
            key: 'by_sede',
            title: __('Por sede'),
            columns: [
                ReportColumn::text('sede', __('Sede')),
                ...$this->sectionColumns(),
                ReportColumn::money('total', __('Total perdido')),
                ReportColumn::money('recaudado', __('Recaudado')),
                ReportColumn::percent('pct', __('Perdido sobre lo recaudado')),
            ],
            rows: $rows,
            totals: $this->sumColumns($rows, ['mostrador', 'peso', 'existencias', 'caja', 'devoluciones', 'total', 'recaudado']),
        );
    }

    private function detailTable(): ReportTable
    {
        $events = $this->events();
        ['page' => $page, 'pages' => $pages] = $this->detailPages();
        $title = $this->sectionFilter !== null ? __('Detalle: :section', ['section' => self::sectionTitles()[$this->sectionFilter]]) : __('Detalle');

        return new ReportTable(
            key: 'detail',
            title: $title,
            columns: [
                ReportColumn::datetime('fecha', __('Fecha')),
                ReportColumn::text('sede', __('Sede')),
                ReportColumn::text('concepto', __('Concepto')),
                ReportColumn::text('que', __('Qué')),
                ReportColumn::text('persona', __('Persona')),
                ReportColumn::text('socio', __('Socio')),
                ReportColumn::weight('gramos', __('Gramos'), total: false),
                ReportColumn::money('importe', __('Importe'), total: false),
                ReportColumn::text('aportacion', __('Al precio de aportación'), sortable: false),
                ReportColumn::text('motivo', __('Motivo'), sortable: false),
            ],
            rows: array_slice($events, ($page - 1) * self::DETAIL_PER_PAGE, self::DETAIL_PER_PAGE),
            empty: __('Nada que mostrar con estos filtros.'),
            note: $pages > 1 ? __('Página :page de :pages (:count movimientos).', ['page' => $page, 'pages' => $pages, 'count' => count($events)]) : null,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $columns
     * @return array<string, int>
     */
    private function sumColumns(array $rows, array $columns): array
    {
        return array_combine($columns, array_map(fn (string $c): int => (int) array_sum(array_column($rows, $c)), $columns));
    }

    // --- Names ----------------------------------------------------------------------------------------------------------

    /** @var array<string, string>|null */
    private ?array $names = null;

    private function name(?string $id): string
    {
        if ($id === null) {
            return '—';
        }
        if ($this->names === null) {
            $ids = array_unique(array_filter([...array_column($this->data()['events'], 'operator_id'), ...array_keys($this->data()['takings'])], fn ($v): bool => $v !== null && $v !== 'none'));
            $this->names = User::query()->withTrashed()->whereIn('id', $ids)->get(['id', 'name', 'deleted_at'])
                ->mapWithKeys(fn (User $u): array => [$u->id => $u->name.($u->deleted_at !== null ? ' ('.__('ya no está').')' : '')])->all();
        }

        return $this->names[$id] ?? '—';
    }

    // --- The one pass -----------------------------------------------------------------------------------------------------

    /** @return array{events: list<Event>, takings: array<string, int>, takings_by_sede: array<string, int>, sales: list<array{at: string, location_id: string, operator_id: ?string, cents: int}>} */
    private function data(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        [$start, $end] = $this->bounds();
        $ids = $this->resolvedLocationIds();
        $events = [];
        $takings = [];
        $takingsBySede = [];
        $sales = []; // prompt 375 — each sale's time, so a window inside the period (yesterday in the last 7 days) can be read
        $event = function (string $section, string $line, mixed $at, mixed $locationId, ?string $operatorId, ?string $memberId, int $cents, int $grams = 0, ?int $contribution = null, ?string $reason = null, string $what = '', ?string $url = null, ?string $flag = null, bool $noCost = false) use (&$events): void {
            $events[] = ['section' => $section, 'line' => $line, 'at' => (string) $at, 'location_id' => (string) $locationId, 'operator_id' => $operatorId,
                'member_id' => $memberId, 'cents' => $cents, 'grams' => $grams, 'contribution' => $contribution, 'reason' => filled($reason) ? (string) $reason : null,
                'what' => $what, 'url' => $url, 'flag' => $flag, 'no_cost' => $noCost];
        };

        // 1. Completed dispensations: takings, whole-euro rounding (350).
        $dispensations = DB::table('dispensations')
            ->whereIn('location_id', $ids)->where('status', DispensationStatus::COMPLETED->value)
            ->where('dispensed_at', '>=', $start)->where('dispensed_at', '<', $end)
            ->get(['id', 'operator_id', 'total_cents', 'dispensed_at', 'location_id', 'member_id', 'rounding_cents'])->keyBy('id');
        foreach ($dispensations as $d) {
            $takings[$d->operator_id ?? 'none'] = ($takings[$d->operator_id ?? 'none'] ?? 0) + (int) $d->total_cents;
            $takingsBySede[$d->location_id] = ($takingsBySede[$d->location_id] ?? 0) + (int) $d->total_cents;
            $sales[] = ['at' => (string) $d->dispensed_at, 'location_id' => (string) $d->location_id, 'operator_id' => $d->operator_id, 'cents' => (int) $d->total_cents];
            if ((int) $d->rounding_cents !== 0) {
                // Signed: a negative rounding is what the member kept — a loss; a positive one was taken — an offset.
                $event('mostrador', 'rounding', $d->dispensed_at, $d->location_id, $d->operator_id, $d->member_id, -(int) $d->rounding_cents,
                    url: DispensationResource::getUrl('view', ['record' => $d->id]));
            }
        }

        // 2. Price adjustments — the Discounts and Consumption reports' query (GivenAwayQueries), split in PHP (370).
        foreach (GivenAwayQueries::priceOverrides($ids, $start, $end)->get([
            'dispensations.id', 'dispensations.operator_id', 'dispensations.price_override_by', 'dispensations.total_cents', 'dispensations.original_total_cents',
            'dispensations.price_override_reason', 'dispensations.dispensed_at', 'dispensations.location_id', 'dispensations.member_id',
        ]) as $d) {
            $given = (int) $d->original_total_cents - (int) $d->total_cents;
            if ($given !== 0) {
                $event('mostrador', $given > 0 ? 'overrides_given' : 'overrides_recovered', $d->dispensed_at, $d->location_id, $d->price_override_by ?? $d->operator_id,
                    $d->member_id, $given, reason: $d->price_override_reason, url: DispensationResource::getUrl('view', ['record' => $d->id]));
            }
        }

        // 3–4. The dispensary's lines, in one query: member discounts — information, by the kind stored on the line (prompt 375;
        //      the staff member's own apart) — and Peso de más (355): each weight line's weighed − charged grams at the line's
        //      own price, read raw and subtracted here (370).
        $lines = DB::table('dispensation_lines')
            ->join('dispensations', 'dispensation_lines.dispensation_id', '=', 'dispensations.id')
            ->whereIn('dispensations.location_id', $ids)->where('dispensations.status', DispensationStatus::COMPLETED->value)
            ->where('dispensations.dispensed_at', '>=', $start)->where('dispensations.dispensed_at', '<', $end)
            ->where(fn ($q) => $q->where('dispensation_lines.discount_cents', '>', 0)
                ->orWhere(fn ($q) => $q->whereNotNull('dispensation_lines.charged_cg')->whereNull('dispensation_lines.units_dispensed')
                    ->whereColumn('dispensation_lines.grams_cg', '<>', 'dispensation_lines.charged_cg')))
            ->get(['dispensation_lines.dispensation_id as id', 'dispensation_lines.discount_cents', 'dispensation_lines.discount_kind', 'dispensation_lines.grams_cg',
                'dispensation_lines.charged_cg', 'dispensation_lines.units_dispensed', 'dispensation_lines.price_per_gram_cents', 'dispensation_lines.genetic_name_snapshot',
                'dispensation_lines.batch_no_snapshot']);
        $discountBySale = []; // sale id => kind => cents
        foreach ($lines as $line) {
            if ((int) $line->discount_cents > 0) {
                $kind = (string) ($line->discount_kind ?? '');
                $discountBySale[$line->id][$kind] = ($discountBySale[$line->id][$kind] ?? 0) + (int) $line->discount_cents;
            }
            $d = $dispensations->get($line->id);
            $over = $line->charged_cg !== null && $line->units_dispensed === null ? (int) $line->grams_cg - (int) $line->charged_cg : 0;
            if ($d !== null && $over !== 0) {
                $event('peso', $over > 0 ? 'weight_over' : 'weight_under', $d->dispensed_at, $d->location_id, $d->operator_id, $d->member_id,
                    (int) round_half_up($over * (int) $line->price_per_gram_cents / 100), $over,
                    what: trim(((string) $line->genetic_name_snapshot).' · '.((string) $line->batch_no_snapshot), ' ·'),
                    url: DispensationResource::getUrl('view', ['record' => $d->id]));
            }
        }
        // Who is staff is only asked for a discount with no stored kind (a line from before 375), and only once.
        $staffMembers = null;
        $discountLine = function (string $kind, ?string $memberId) use (&$staffMembers): string {
            if ($kind === '' && $memberId !== null) {
                $staffMembers ??= array_flip(User::query()->withTrashed()->whereNotNull('member_id')->pluck('member_id')->all());
            }

            return self::discountLine($kind, $kind === '' && $memberId !== null && isset($staffMembers[$memberId]));
        };
        foreach ($discountBySale as $id => $byKind) {
            $d = $dispensations->get($id);
            foreach ($d !== null ? $byKind : [] as $kind => $cents) {
                $event('mostrador', $discountLine((string) $kind, $d->member_id), $d->dispensed_at, $d->location_id,
                    $d->operator_id, $d->member_id, $cents, url: DispensationResource::getUrl('view', ['record' => $d->id]));
            }
        }

        // 5. Bar & shop: takings and member discounts (the order snapshot, summed in PHP — no JSON-path SQL), and voided orders
        //    no fresh order corrects.
        DB::table('orders')
            ->whereIn('location_id', $ids)->where('status', OrderStatus::COMPLETED->value)
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->select(['id', 'operator_id', 'total_cents', 'items', 'created_at', 'location_id', 'member_id'])->orderBy('id')
            ->chunk(500, function ($orders) use (&$takings, &$takingsBySede, &$sales, $event, $discountLine): void {
                foreach ($orders as $o) {
                    $takings[$o->operator_id ?? 'none'] = ($takings[$o->operator_id ?? 'none'] ?? 0) + (int) $o->total_cents;
                    $takingsBySede[$o->location_id] = ($takingsBySede[$o->location_id] ?? 0) + (int) $o->total_cents;
                    $sales[] = ['at' => (string) $o->created_at, 'location_id' => (string) $o->location_id, 'operator_id' => $o->operator_id, 'cents' => (int) $o->total_cents];
                    $byKind = [];
                    foreach ((array) json_decode((string) $o->items, true) as $item) {
                        if (is_array($item) && ($item['article_id'] ?? null) !== null && (int) ($item['discount_cents'] ?? 0) > 0) {
                            $kind = (string) ($item['discount_kind'] ?? '');
                            $byKind[$kind] = ($byKind[$kind] ?? 0) + (int) $item['discount_cents'];
                        }
                    }
                    foreach ($byKind as $kind => $cents) {
                        $event('mostrador', $discountLine((string) $kind, $o->member_id), $o->created_at, $o->location_id, $o->operator_id, $o->member_id, $cents,
                            what: __('Barra y tienda'), url: OrderResource::getUrl('view', ['record' => $o->id]));
                    }
                }
            });
        foreach (DB::table('orders as o')
            ->whereIn('o.location_id', $ids)->where('o.status', OrderStatus::VOIDED->value)
            ->where('o.voided_at', '>=', $start)->where('o.voided_at', '<', $end)
            ->whereNotExists(fn ($q) => $q->from('orders as c')->whereColumn('c.reversal_of_id', 'o.id'))
            ->get(['o.id', 'o.voided_by', 'o.operator_id', 'o.total_cents', 'o.void_reason', 'o.voided_at', 'o.location_id', 'o.member_id']) as $o) {
            $event('devoluciones', 'voided_orders', $o->voided_at, $o->location_id, $o->voided_by ?? $o->operator_id, $o->member_id, (int) $o->total_cents,
                reason: $o->void_reason, url: OrderResource::getUrl('view', ['record' => $o->id]));
        }

        // 6. Waived fees — the Financial and Discounts reports' query.
        foreach (GivenAwayQueries::waivedFees($ids, $start, $end)->get([
            'membership_fee_payments.recorded_by as by', 'membership_fee_payments.amount_cents as cents', 'membership_fee_payments.reason as reason',
            'membership_fee_payments.paid_at as at', 'memberships.location_id as location_id', 'memberships.member_id as member_id',
        ]) as $w) {
            $event('mostrador', 'waived_fees', $w->at, $w->location_id, $w->by, $w->member_id, (int) $w->cents, reason: $w->reason);
        }

        // 7. Stock lost — merma and adjustments on batches, valued at cost (the total) and at the contribution price.
        $this->stockEvents($ids, $start, $end, $event);

        // 8. Till differences — every counted pot, sessions opened in the period (as Informes → Cajas).
        $sessions = TillSession::query()->withoutGlobalScopes()
            ->whereIn('location_id', $ids)->where('status', TillSessionStatus::CLOSED)
            ->where('opened_at', '>=', $start)->where('opened_at', '<', $end)->get();
        foreach ($sessions as $session) {
            $flag = $session->closedUnexplained() ? __('Sin explicar') : null;
            $pots = [CashPot::DISPENSARY->value => $session->getRawOriginal('variance_cents')];
            foreach ($session->ownBoxes() as $pot) {
                $pots[$pot->value] = $session->getRawOriginal($pot->column().'_variance_cents');
            }
            foreach ($pots as $pot => $variance) {
                if ($variance !== null && (int) $variance !== 0) {
                    $label = count($pots) > 1 ? CashPot::from($pot)->label() : null;
                    $event('caja', (int) $variance < 0 ? 'till_short' : 'till_over', $session->closed_at ?? $session->opened_at, $session->location_id, $session->closed_by,
                        null, -(int) $variance, reason: $session->notes, what: trim($session->terminal.($label !== null ? ' · '.$label : '')),
                        url: TillSessionResource::getUrl('view', ['record' => $session->id]), flag: $flag);
                }
            }
        }

        // 9. Refunds, and voided dispensations no fresh row corrects.
        foreach (DB::table('refunds')->whereIn('location_id', $ids)->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->get(['dispensation_id', 'member_id', 'amount_cents', 'grams_cg', 'reason', 'refunded_by', 'created_at', 'location_id']) as $r) {
            $event('devoluciones', 'refunds', $r->created_at, $r->location_id, $r->refunded_by, $r->member_id, (int) $r->amount_cents, (int) $r->grams_cg,
                reason: $r->reason, url: DispensationResource::getUrl('view', ['record' => $r->dispensation_id]));
        }
        foreach (DB::table('dispensations as d')
            ->whereIn('d.location_id', $ids)->where('d.status', DispensationStatus::VOIDED->value)
            ->where('d.voided_at', '>=', $start)->where('d.voided_at', '<', $end)
            ->whereNotExists(fn ($q) => $q->from('dispensations as c')->whereColumn('c.reversal_of_id', 'd.id'))
            ->get(['d.id', 'd.voided_by', 'd.operator_id', 'd.total_cents', 'd.void_reason', 'd.voided_at', 'd.location_id', 'd.member_id']) as $d) {
            $event('devoluciones', 'voided_dispensations', $d->voided_at, $d->location_id, $d->voided_by ?? $d->operator_id, $d->member_id, (int) $d->total_cents,
                reason: $d->void_reason, url: DispensationResource::getUrl('view', ['record' => $d->id]));
        }

        return $this->data = ['events' => $events, 'takings' => $takings, 'takings_by_sede' => $takingsBySede, 'sales' => $sales];
    }

    /**
     * Prompt 375 — which *mostrador* line a member discount goes on: its stored kind; the staff discount (or, for a line from
     * before the kind was stored, a member record linked to a staff account, 347) on the staff line; else «sin clasificar».
     */
    private static function discountLine(string $kind, bool $staffMember): string
    {
        return match ($kind) {
            DiscountKind::STAFF->value => 'member_discounts_staff',
            DiscountKind::LOCAL->value => 'member_discounts_local',
            DiscountKind::CONCESSION->value => 'member_discounts_concession',
            DiscountKind::THERAPEUTIC->value => 'member_discounts_therapeutic',
            DiscountKind::CUSTOM->value => 'member_discounts_custom',
            'TIER' => 'member_discounts_tier',
            default => $kind === '' && $staffMember ? 'member_discounts_staff' : 'member_discounts_unclassified',
        };
    }

    /**
     * Merma and adjustments on batches. Not read: RESERVE_* (moves inside a batch: *Rellenar*, *Rellenado sin registrar*),
     * and any movement with a `reference` (a void's or a refund's stock coming back, or a refund's merma — counted once, as the
     * refund or the void). Bar products carry no cost and are left out.
     *
     * @param  list<string>  $ids
     */
    private function stockEvents(array $ids, CarbonImmutable $start, CarbonImmutable $end, Closure $event): void
    {
        $movements = DB::table('stock_movements')
            ->whereIn('location_id', $ids)->where('stockable_type', (new Batch)->getMorphClass())
            ->whereIn('type', [StockMovementType::MERMA->value, StockMovementType::ADJUSTMENT->value])
            ->whereNull('reference')
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->get(['id', 'type', 'qty_cg', 'qty_units', 'reason', 'operator_id', 'stock_take_id', 'created_at', 'location_id', 'stockable_id', 'on_reserve']);
        if ($movements->isEmpty()) {
            return;
        }

        $batches = DB::table('batches')->leftJoin('genetics', 'batches.genetic_id', '=', 'genetics.id')
            ->whereIn('batches.id', $movements->pluck('stockable_id')->unique()->all())
            ->get(['batches.id', 'batches.genetic_id', 'batches.location_id', 'batches.batch_no', 'batches.label', 'batches.cost_per_gram_cents', 'batches.price_per_gram_cents',
                'batches.price_per_unit_cents', 'genetics.name as genetic', 'genetics.unit_type', 'genetics.grams_per_unit_cg'])->keyBy('id');
        // An unpriced batch is priced as the counter prices it (278): the strain's base price at the sede.
        $basePrices = DB::table('genetic_prices')->whereIn('genetic_id', $batches->pluck('genetic_id')->unique()->all())->whereIn('location_id', $ids)
            ->whereNull('tier_id')->where('active', true)->get(['genetic_id', 'location_id', 'price_per_gram_cents', 'price_per_unit_cents'])
            ->keyBy(fn ($p): string => $p->genetic_id.'|'.$p->location_id);
        $kinds = DB::table('stock_takes')->whereIn('id', $movements->pluck('stock_take_id')->filter()->unique()->all())->pluck('kind', 'id');
        $unexplained = array_flip(DB::table('audit_logs')->where('action', 'stock.count_unexplained')->where('auditable_type', (new StockTake)->getMorphClass())
            ->whereIn('auditable_id', $kinds->keys()->all())->pluck('auditable_id')->all());

        foreach ($movements as $m) {
            $b = $batches->get($m->stockable_id);
            if ($b === null) {
                continue;
            }
            $unit = $b->unit_type === 'UNIT';
            $qty = $unit ? (int) $m->qty_units : (int) $m->qty_cg; // signed: negative is stock gone
            if ($qty === 0) {
                continue;
            }
            $lost = -$qty;
            $grams = $unit ? $lost * (int) $b->grams_per_unit_cg : $lost;
            $base = $basePrices->get($b->genetic_id.'|'.$b->location_id);
            $rate = $unit ? ($b->price_per_unit_cents ?? $base?->price_per_unit_cents) : ($b->price_per_gram_cents ?? $base?->price_per_gram_cents);
            // Prompt 375 — a batch with no cost recorded: «sin coste registrado», never a cost invented or the contribution price
            // added; the movement is counted apart so the section can say it is not in the total.
            $noCost = (int) $b->cost_per_gram_cents <= 0;
            $cost = $noCost ? 0 : (int) round_half_up($grams * (int) $b->cost_per_gram_cents / 100);
            $contribution = $rate === null ? null : (int) round_half_up($unit ? $lost * (int) $rate : $lost * (int) $rate / 100);

            $line = match (true) {
                $lost < 0 => 'stock_gains',
                $m->type === StockMovementType::MERMA->value => 'merma',
                $m->stock_take_id !== null && ($kinds[$m->stock_take_id] ?? null) === StockTake::KIND_INVENTORY => 'count_inventory',
                $m->stock_take_id !== null => 'count_till',
                default => 'adjustments',
            };
            $what = trim(((string) $b->genetic).' · '.((string) ($b->label ?? $b->batch_no)).((bool) $m->on_reserve ? ' · '.__('reserva sellada') : ''), ' ·');
            $event('existencias', $line, $m->created_at, $m->location_id, $m->operator_id, null, $cost, $grams, $contribution,
                reason: $m->reason, what: $what, url: BatchResource::getUrl('edit', ['record' => $b->id]),
                flag: $m->stock_take_id !== null && isset($unexplained[$m->stock_take_id]) ? __('Sin explicar') : null, noCost: $noCost);
        }
    }
}
