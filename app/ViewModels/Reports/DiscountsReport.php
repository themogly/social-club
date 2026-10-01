<?php

namespace App\ViewModels\Reports;

use App\Enums\DispensationStatus;
use App\Enums\OrderStatus;
use App\Filament\Pages\Reports\DiscountsReportPage;
use App\Filament\Resources\Dispensations\DispensationResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Location;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Money;
use App\Support\Period;
use App\Support\Reports\GivenAwayQueries;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Prompt 291 — Descuentos y ajustes: everything given away, by whom, in one report.
 *
 * Four kinds, kept apart because they mean different things:
 * - **member discounts** (tier, assigned, Personalizado) follow the MEMBER, never the operator — information only;
 * - **price overrides** and **waived fees** are discretionary acts — together, as a share of the operator's own takings,
 *   they are the "discretionary %" the alert watches;
 * - **manual bar lines** are money TAKEN, not forgone — never in "Total cedido", but counted, because selling a catalogue
 *   item as a cheap manual line is where undercharging hides.
 *
 * Voided sales count nowhere. Overrides and waivers come from the SAME queries as the Consumption and Financial reports
 * ({@see GivenAwayQueries}). Bar lines live in a JSON snapshot: loaded in chunks with only the needed columns and summed
 * in PHP — no JSON-path SQL, so SQLite and MySQL cannot disagree. Bounded queries whatever the number of sales.
 *
 * @phpstan-type OperatorRow array{operador: string, operator_id: ?string, ventas: int, recaudado: int, ajustes: int, ajustes_importe: int, condonaciones: int, condonaciones_importe: int, manuales: int, manuales_importe: int, discrecional: int, discrecional_pct: int, descuentos_socio: int, redondeo: int}
 */
class DiscountsReport extends AbstractReport
{
    public const DETAIL_PER_PAGE = 50;

    /** A discretionary share over the threshold only counts with at least this much takings (€50) — OVERNIGHT-DEFAULT — CONFIRM. */
    public const ALERT_MIN_TAKINGS_CENTS = 5000;

    public const ALERT_DAYS = 7;

    private ?string $kindFilter = null;

    private ?string $operatorFilter = null;

    private int $detailPage = 1;

    /** @var array{operators: array<string, OperatorRow>, types: array<string, int>, events: list<array<string, mixed>>, totals: array<string, int>, rounding_by_sede: array<string, int>}|null */
    private ?array $data = null;

    public function key(): string
    {
        return 'descuentos';
    }

    public function title(): string
    {
        return __('Descuentos y ajustes');
    }

    /** The detail's filters and page (the page's URL state). */
    public function withDetail(?string $kind, ?string $operatorId, int $page): static
    {
        $this->kindFilter = in_array($kind, ['ajuste', 'condonacion', 'manual', 'descuento'], true) ? $kind : null;
        $this->operatorFilter = filled($operatorId) ? $operatorId : null;
        $this->detailPage = max(1, $page);

        return $this;
    }

    protected function build(): array
    {
        return [$this->byOperator(), $this->byType(), $this->roundingBySede(), $this->detail()];
    }

    public function summary(): array
    {
        $t = $this->data()['totals'];
        $given = $t['member_discounts'] + $t['overrides'] + $t['waivers'];
        $share = $t['takings'] > 0 ? (int) round($given / $t['takings'] * 100) : 0;

        return [
            ['key' => 'member_discounts', 'label' => __('Descuentos de socio'), 'value' => Money::fromCents($t['member_discounts'])->formatted()],
            ['key' => 'overrides', 'label' => __('Ajustes de precio'), 'value' => Money::fromCents($t['overrides'])->formatted(), 'tone' => $t['overrides'] > 0 ? 'warning' : 'success'],
            ['key' => 'waivers', 'label' => __('Cuotas condonadas'), 'value' => Money::fromCents($t['waivers'])->formatted(), 'tone' => $t['waivers'] > 0 ? 'warning' : 'success'],
            ['key' => 'manual_lines', 'label' => __('Líneas manuales'), 'value' => trans_choice(':count línea|:count líneas', $t['manual_count'], ['count' => $t['manual_count']]).' · '.Money::fromCents($t['manual'])->formatted()],
            ['key' => 'total_given', 'label' => __('Total cedido'), 'value' => Money::fromCents($given)->formatted().' · '.$share.' %'],
            // Prompt 350 — the net effect of whole-euro rounding (negative: given away to members; positive: rounded up).
            ['key' => 'rounding', 'label' => __('Redondeo'), 'value' => Money::fromCents($t['rounding'])->formatted()],
        ];
    }

    /**
     * How many operators, over the last 7 days (whatever the dashboard period), gave away — overrides + waivers — more
     * than the threshold % of their own takings, with at least €50 of takings. The dashboard alert.
     *
     * @param  list<string>  $locationIds
     */
    public static function operatorsAboveThreshold(array $locationIds): int
    {
        if ($locationIds === []) {
            return 0;
        }

        try {
            $threshold = max(0, (int) Settings::get('discount_alert_threshold_pct', 10));
            $end = CarbonImmutable::now();
            $report = new self((string) app(ActiveScope::class)->organisationId(), $locationIds, Period::custom($end->subDays(self::ALERT_DAYS), $end));

            return count(array_filter($report->data()['operators'], fn (array $row): bool => $row['operator_id'] !== null
                && $row['recaudado'] >= self::ALERT_MIN_TAKINGS_CENTS
                && $threshold * $row['recaudado'] < $row['discrecional'] * 100));
        } catch (Throwable) {
            return 0; // an alert must never break the dashboard
        }
    }

    // --- Tables ----------------------------------------------------------------------------------------------------

    private function byOperator(): ReportTable
    {
        $rows = array_values(array_map(function (array $row): array {
            if ($row['operator_id'] !== null) {
                $row['operador__url'] = DiscountsReportPage::getUrl(['operator' => $row['operator_id']]);
            }

            return $row;
        }, $this->data()['operators']));

        return new ReportTable(
            key: 'by_operator',
            title: __('Por operador'),
            columns: [
                ReportColumn::text('operador', __('Operador')),
                ReportColumn::number('ventas', __('Operaciones')), // never «ventas» in a report (CLAUDE.md vocabulary)
                ReportColumn::money('recaudado', __('Recaudado')),
                ReportColumn::number('ajustes', __('Ajustes')),
                ReportColumn::money('ajustes_importe', __('Importe ajustes')),
                ReportColumn::number('condonaciones', __('Condonaciones')),
                ReportColumn::money('condonaciones_importe', __('Importe condonado')),
                ReportColumn::number('manuales', __('Líneas manuales')),
                ReportColumn::money('manuales_importe', __('Importe líneas manuales')),
                ReportColumn::money('discrecional', __('Discrecional')),
                ReportColumn::number('discrecional_pct', __('Discrecional (%)'), total: false),
                ReportColumn::money('descuentos_socio', __('Descuentos de socio (informativo)')),
                ReportColumn::money('redondeo', __('Redondeo')), // prompt 350 — net, signed
            ],
            rows: $rows,
            empty: __('Nadie ha registrado operaciones en este período.'),
            defaultSort: 'discrecional_pct',
            sortable: true,
            note: __('Discrecional = ajustes de precio + cuotas condonadas, sobre lo recaudado por esa persona. Los descuentos de socio siguen al socio, no a quien atiende: se muestran solo como información.'),
        );
    }

    /** Prompt 350 — the period's net rounding per sede (signed: negative is what members kept). */
    private function roundingBySede(): ReportTable
    {
        $names = Location::query()->withoutGlobalScopes()->whereIn('id', array_keys($this->data()['rounding_by_sede']))->pluck('name', 'id');
        $rows = [];
        foreach ($this->data()['rounding_by_sede'] as $sedeId => $cents) {
            $rows[] = ['sede' => (string) ($names[$sedeId] ?? $sedeId), 'redondeo' => $cents];
        }

        return new ReportTable(
            key: 'rounding_by_sede',
            title: __('Redondeo por sede'),
            columns: [ReportColumn::text('sede', __('Sede')), ReportColumn::money('redondeo', __('Redondeo'))],
            rows: $rows,
            totals: ['redondeo' => array_sum(array_column($rows, 'redondeo'))],
            empty: __('Ninguna aportación se ha redondeado en este período.'),
            note: __('Neto del redondeo al euro de los totales con descuento: negativo es lo que se quedaron los socios.'),
        );
    }

    private function byType(): ReportTable
    {
        $rows = [];
        foreach ($this->data()['types'] as $label => $cents) {
            $rows[] = ['tipo' => $label, 'importe' => $cents];
        }

        return new ReportTable(
            key: 'by_type',
            title: __('Por tipo de descuento'),
            columns: [ReportColumn::text('tipo', __('Descuento')), ReportColumn::money('importe', __('Importe'))],
            rows: $rows,
            totals: ['importe' => array_sum(array_column($rows, 'importe'))],
            empty: __('No se ha aplicado ningún descuento de socio en este período.'),
            defaultSort: 'importe',
            sortable: true,
        );
    }

    private function detail(): ReportTable
    {
        $events = array_values(array_filter($this->data()['events'], fn (array $e): bool => ($this->kindFilter === null || $e['kind'] === $this->kindFilter)
            && ($this->operatorFilter === null || $e['operator_id'] === $this->operatorFilter)));
        usort($events, fn (array $a, array $b): int => strcmp((string) $b['fecha'], (string) $a['fecha']));

        $pages = max(1, (int) ceil(count($events) / self::DETAIL_PER_PAGE));
        $page = min($this->detailPage, $pages);
        $rows = array_map(fn (array $e): array => array_diff_key($e, ['kind' => 1, 'operator_id' => 1]),
            array_slice($events, ($page - 1) * self::DETAIL_PER_PAGE, self::DETAIL_PER_PAGE));

        return new ReportTable(
            key: 'detail',
            title: __('Detalle'),
            columns: [
                ReportColumn::datetime('fecha', __('Fecha')),
                ReportColumn::text('sede', __('Sede')),
                ReportColumn::text('operador', __('Operador')),
                ReportColumn::text('tipo', __('Tipo')),
                ReportColumn::text('socio', __('Socio')),
                ReportColumn::money('importe', __('Importe')),
                ReportColumn::text('motivo', __('Motivo'), sortable: false),
            ],
            rows: $rows,
            empty: __('Nada que mostrar con estos filtros.'),
            note: count($events) > self::DETAIL_PER_PAGE ? __('Página :page de :pages (:count movimientos).', ['page' => $page, 'pages' => $pages, 'count' => count($events)]) : null,
        );
    }

    /**
     * The current page of the detail and how many pages there are, for the pager.
     *
     * @return array{page: int, pages: int}
     */
    public function detailPages(): array
    {
        $events = array_filter($this->data()['events'], fn (array $e): bool => ($this->kindFilter === null || $e['kind'] === $this->kindFilter)
            && ($this->operatorFilter === null || $e['operator_id'] === $this->operatorFilter));
        $pages = max(1, (int) ceil(count($events) / self::DETAIL_PER_PAGE));

        return ['page' => min($this->detailPage, $pages), 'pages' => $pages];
    }

    /** @return array<string, string> operator id => name, for the detail's filter */
    public function operatorOptions(): array
    {
        $options = [];
        foreach ($this->data()['operators'] as $row) {
            if ($row['operator_id'] !== null) {
                $options[$row['operator_id']] = $row['operador'];
            }
        }

        return $options;
    }

    // --- The one pass -------------------------------------------------------------------------------------------------

    /**
     * Every figure, from one bounded pass: dispensations (+ their discounted lines), orders (chunked), waivers.
     *
     * @return array{operators: array<string, OperatorRow>, types: array<string, int>, events: list<array<string, mixed>>, totals: array<string, int>, rounding_by_sede: array<string, int>}
     */
    private function data(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        [$start, $end] = $this->bounds();
        $ids = $this->resolvedLocationIds();
        $ops = [];
        $types = [];
        $events = [];
        $totals = ['member_discounts' => 0, 'overrides' => 0, 'waivers' => 0, 'manual' => 0, 'manual_count' => 0, 'takings' => 0, 'rounding' => 0];
        $roundingBySede = [];
        $touch = function (?string $id) use (&$ops): string {
            $key = $id ?? 'none';
            $ops[$key] ??= ['operador' => '', 'operator_id' => $id, 'ventas' => 0, 'recaudado' => 0, 'ajustes' => 0, 'ajustes_importe' => 0,
                'condonaciones' => 0, 'condonaciones_importe' => 0, 'manuales' => 0, 'manuales_importe' => 0, 'discrecional' => 0,
                'discrecional_pct' => 0, 'descuentos_socio' => 0, 'redondeo' => 0];

            return $key;
        };

        // 1. Completed dispensations — takings, overrides (attributed to who AUTHORISED the price, else the operator).
        $dispensations = DB::table('dispensations')
            ->whereIn('location_id', $ids)->where('status', DispensationStatus::COMPLETED->value)
            ->where('dispensed_at', '>=', $start)->where('dispensed_at', '<', $end)
            ->get(['id', 'operator_id', 'price_override_by', 'total_cents', 'original_total_cents', 'price_override_reason', 'dispensed_at', 'location_id', 'member_id', 'self_dispensed', 'rounding_cents']);
        $overrideIds = GivenAwayQueries::priceOverrides($ids, $start, $end)->pluck('dispensations.id')->flip();

        // 2. Their discounted lines, per sale and label (one query).
        $lineDiscounts = DB::table('dispensation_lines')
            ->join('dispensations', 'dispensation_lines.dispensation_id', '=', 'dispensations.id')
            ->whereIn('dispensations.location_id', $ids)->where('dispensations.status', DispensationStatus::COMPLETED->value)
            ->where('dispensations.dispensed_at', '>=', $start)->where('dispensations.dispensed_at', '<', $end)
            ->where('dispensation_lines.discount_cents', '>', 0)
            ->groupBy('dispensation_lines.dispensation_id', 'dispensation_lines.pricing_note')
            ->get(['dispensation_lines.dispensation_id as id', 'dispensation_lines.pricing_note as note', DB::raw('SUM(dispensation_lines.discount_cents) as cents')]);
        $discountBySale = [];
        foreach ($lineDiscounts as $row) {
            $label = filled($row->note) ? (string) $row->note : __('Descuento de socio');
            $types[$label] = ($types[$label] ?? 0) + (int) $row->cents;
            $discountBySale[$row->id] = ($discountBySale[$row->id] ?? 0) + (int) $row->cents;
        }

        foreach ($dispensations as $d) {
            $k = $touch($d->operator_id);
            $ops[$k]['ventas']++;
            $ops[$k]['recaudado'] += (int) $d->total_cents;
            $totals['takings'] += (int) $d->total_cents;

            if (isset($discountBySale[$d->id])) {
                $ops[$k]['descuentos_socio'] += $discountBySale[$d->id];
                $totals['member_discounts'] += $discountBySale[$d->id];
                $events[] = $this->event('descuento', __('Descuento de socio'), $d->dispensed_at, $d->location_id, $d->operator_id, $d->member_id, $discountBySale[$d->id], null, DispensationResource::getUrl('view', ['record' => $d->id]));
            }

            // Prompt 350 — whole-euro rounding: per operator, per sede, and an event in the detail.
            if ((int) $d->rounding_cents !== 0) {
                $ops[$k]['redondeo'] += (int) $d->rounding_cents;
                $totals['rounding'] += (int) $d->rounding_cents;
                $roundingBySede[(string) $d->location_id] = ($roundingBySede[(string) $d->location_id] ?? 0) + (int) $d->rounding_cents;
                $events[] = $this->event('redondeo', __('Redondeo'), $d->dispensed_at, $d->location_id, $d->operator_id, $d->member_id, (int) $d->rounding_cents, null, DispensationResource::getUrl('view', ['record' => $d->id]));
            }

            // Prompt 347 — a member of staff served their own member record: listed (the whole contribution), filterable
            // in the detail as «Auto-dispensación». Not a discount in itself, so it adds to no discretionary total.
            if ((bool) $d->self_dispensed) {
                $events[] = $this->event('auto', __('Auto-dispensación'), $d->dispensed_at, $d->location_id, $d->operator_id, $d->member_id, (int) $d->total_cents, null, DispensationResource::getUrl('view', ['record' => $d->id]));
            }

            if ($overrideIds->has($d->id)) {
                $value = (int) $d->original_total_cents - (int) $d->total_cents;
                $by = $touch($d->price_override_by ?? $d->operator_id);
                $ops[$by]['ajustes']++;
                $ops[$by]['ajustes_importe'] += $value;
                $totals['overrides'] += $value;
                $events[] = $this->event('ajuste', __('Ajuste'), $d->dispensed_at, $d->location_id, $d->price_override_by ?? $d->operator_id, $d->member_id, $value, $d->price_override_reason, DispensationResource::getUrl('view', ['record' => $d->id]));
            }
        }

        // 3. Completed orders, chunked, items summed in PHP (no JSON-path SQL).
        DB::table('orders')
            ->whereIn('location_id', $ids)->where('status', OrderStatus::COMPLETED->value)
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->select(['id', 'operator_id', 'total_cents', 'items', 'created_at', 'location_id', 'member_id'])
            ->orderBy('id')
            ->chunk(500, function ($orders) use (&$ops, &$types, &$events, &$totals, $touch): void {
                foreach ($orders as $o) {
                    $k = $touch($o->operator_id);
                    $ops[$k]['ventas']++;
                    $ops[$k]['recaudado'] += (int) $o->total_cents;
                    $totals['takings'] += (int) $o->total_cents;

                    $discount = 0;
                    foreach ((array) json_decode((string) $o->items, true) as $item) {
                        if (! is_array($item)) {
                            continue;
                        }
                        if (($item['article_id'] ?? null) === null) {
                            $value = (int) ($item['line_total_cents'] ?? 0);
                            $ops[$k]['manuales']++;
                            $ops[$k]['manuales_importe'] += $value;
                            $totals['manual'] += $value;
                            $totals['manual_count']++;
                            $events[] = $this->event('manual', __('Línea manual'), $o->created_at, $o->location_id, $o->operator_id, $o->member_id, $value,
                                trim(((string) ($item['name'] ?? '')).' · '.((string) ($item['reference'] ?? '')), ' ·'), OrderResource::getUrl('view', ['record' => $o->id]));
                        } else {
                            $discount += (int) ($item['discount_cents'] ?? 0);
                        }
                    }

                    if ($discount > 0) {
                        $label = __('Barra y tienda: descuento de socio');
                        $types[$label] = ($types[$label] ?? 0) + $discount;
                        $ops[$k]['descuentos_socio'] += $discount;
                        $totals['member_discounts'] += $discount;
                        $events[] = $this->event('descuento', __('Descuento de socio'), $o->created_at, $o->location_id, $o->operator_id, $o->member_id, $discount, null, OrderResource::getUrl('view', ['record' => $o->id]));
                    }
                }
            });

        // 4. Waived fees — the Financial report's query.
        foreach (GivenAwayQueries::waivedFees($ids, $start, $end)->get([
            'membership_fee_payments.recorded_by as by', 'membership_fee_payments.amount_cents as cents', 'membership_fee_payments.reason as reason',
            'membership_fee_payments.paid_at as at', 'memberships.location_id as location_id', 'memberships.member_id as member_id',
        ]) as $w) {
            $k = $touch($w->by);
            $ops[$k]['condonaciones']++;
            $ops[$k]['condonaciones_importe'] += (int) $w->cents;
            $totals['waivers'] += (int) $w->cents;
            $events[] = $this->event('condonacion', __('Condonación'), $w->at, $w->location_id, $w->by, $w->member_id, (int) $w->cents, $w->reason, null);
        }

        // 5. Names (soft-deleted staff keep theirs, marked as having left), sedes and member numbers — one query each.
        $users = User::query()->withTrashed()->whereIn('id', array_filter(array_column($ops, 'operator_id')))->get(['id', 'name', 'deleted_at'])->keyBy('id');
        $name = fn (?string $id): string => $id === null ? '—'
            : (($u = $users->get($id)) === null ? '—' : $u->name.($u->deleted_at !== null ? ' ('.__('ya no está').')' : ''));
        $sedes = DB::table('locations')->whereIn('id', array_unique(array_column($events, 'sede')))->pluck('name', 'id');
        $members = DB::table('members')->whereIn('id', array_filter(array_unique(array_column($events, 'socio'))))->pluck('member_no', 'id');

        foreach ($ops as $k => $row) {
            $ops[$k]['operador'] = $name($row['operator_id']);
            $ops[$k]['discrecional'] = $row['ajustes_importe'] + $row['condonaciones_importe'];
            $ops[$k]['discrecional_pct'] = $row['recaudado'] > 0 ? (int) round($ops[$k]['discrecional'] / $row['recaudado'] * 100) : 0;
        }
        foreach ($events as $i => $e) {
            $events[$i]['operador'] = $name($e['operator_id']);
            $events[$i]['sede'] = (string) ($sedes[$e['sede']] ?? '—');
            $events[$i]['socio'] = $e['socio'] !== null ? (string) ($members[$e['socio']] ?? '—') : '—';
        }
        arsort($types);

        return $this->data = ['operators' => $ops, 'types' => $types, 'events' => $events, 'totals' => $totals, 'rounding_by_sede' => $roundingBySede];
    }

    /** @return array<string, mixed> */
    private function event(string $kind, string $label, mixed $at, mixed $locationId, ?string $operatorId, ?string $memberId, int $cents, ?string $reason, ?string $url): array
    {
        return array_filter([
            'kind' => $kind, 'operator_id' => $operatorId, 'fecha' => (string) $at, 'sede' => (string) $locationId,
            'operador' => '', 'tipo' => $label, 'socio' => $memberId, 'importe' => $cents,
            'motivo' => filled($reason) ? (string) $reason : '—', 'fecha__url' => $url,
        ], fn ($v, $k): bool => $k !== 'fecha__url' || $v !== null, ARRAY_FILTER_USE_BOTH);
    }
}
