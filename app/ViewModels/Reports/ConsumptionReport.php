<?php

namespace App\ViewModels\Reports;

use App\Enums\DispensationStatus;
use App\Enums\ProductType;
use App\Models\Member;
use App\Support\ConsumptionForecast;
use App\Support\Period;
use App\Support\Weight;
use Illuminate\Support\Facades\DB;

/**
 * Consumo — a compliance instrument as much as an analytics one. Grams by member and by
 * genetic over the period, the members over their monthly cap, the audited limit-override
 * log, and the declared forecast (each member's previsión) against real dispensing, with
 * the club's aggregate authorised volume. Weight is integer centigrams throughout.
 */
class ConsumptionReport extends AbstractReport
{
    private int $overLimitCount = 0;

    private int $dispensedCg = 0;

    public function key(): string
    {
        return 'consumo';
    }

    public function title(): string
    {
        return __('Informe de consumo');
    }

    protected function build(): array
    {
        return [
            $this->byMember(),
            $this->byGenetic(),
            $this->overLimit(),
            $this->overrideLog(),
        ];
    }

    public function summary(): array
    {
        $this->tables();

        return [
            ['label' => __('Dispensado'), 'value' => Weight::fromCentigrams($this->dispensedCg)->formatted()],
            ['label' => __('Previsión declarada'), 'value' => Weight::fromCentigrams(ConsumptionForecast::authorisedVolumeCg($this->organisationId))->formatted()],
            ['label' => __('Socios sobre límite'), 'value' => (string) $this->overLimitCount, 'tone' => $this->overLimitCount > 0 ? 'warning' : 'success'],
        ];
    }

    // --- Grams by member (forecast vs actual) ---------------------------------------

    private function byMember(): ReportTable
    {
        [$start, $end] = $this->bounds();

        $rows = DB::table('dispensation_lines')
            ->join('dispensations', 'dispensation_lines.dispensation_id', '=', 'dispensations.id')
            ->join('members', 'dispensations.member_id', '=', 'members.id')
            ->whereIn('dispensations.location_id', $this->resolvedLocationIds())
            ->where('dispensations.status', DispensationStatus::COMPLETED->value)
            ->whereBetween('dispensations.dispensed_at', [$start, $end])
            ->groupBy('members.id')
            ->get([
                DB::raw('MAX(members.member_no) as member_no'),
                DB::raw('MAX(members.first_name) as first_name'),
                DB::raw('MAX(members.last_name) as last_name'),
                DB::raw('MAX(members.declared_monthly_cg) as declared_cg'),
                DB::raw('COUNT(DISTINCT dispensation_lines.dispensation_id) as tx'),
                DB::raw('SUM(dispensation_lines.grams_cg) as dispensado_cg'),
            ])
            ->map(function (\stdClass $r): array {
                $declared = (int) $r->declared_cg;
                $dispensado = (int) $r->dispensado_cg;

                return [
                    'member_no' => (string) $r->member_no,
                    'socio' => trim($r->first_name.' '.$r->last_name),
                    'tx' => (int) $r->tx,
                    'declared' => $declared,
                    'dispensado' => $dispensado,
                    'pct' => $declared > 0 ? intdiv($dispensado * 100, $declared) : 0,
                ];
            })->all();

        $totals = [
            'tx' => array_sum(array_column($rows, 'tx')),
            'declared' => array_sum(array_column($rows, 'declared')),
            'dispensado' => array_sum(array_column($rows, 'dispensado')),
        ];
        $this->dispensedCg = $totals['dispensado'];

        return new ReportTable(
            key: 'members',
            title: __('Consumo por socio'),
            columns: [
                ReportColumn::text('member_no', __('Nº')),
                ReportColumn::text('socio', __('Socio')),
                ReportColumn::number('tx', __('Dispensaciones')),
                ReportColumn::weight('declared', __('Previsión')),
                ReportColumn::weight('dispensado', __('Dispensado')),
                ReportColumn::percent('pct', __('% previsión')),
            ],
            rows: $rows,
            totals: $totals,
            empty: __('Nada dispensado en este período'),
            emptyHint: __('El consumo por socio aparece aquí en cuanto se registra una dispensación.'),
            defaultSort: 'dispensado',
            sortable: true,
        );
    }

    // --- By genetic -----------------------------------------------------------------

    private function byGenetic(): ReportTable
    {
        [$start, $end] = $this->bounds();

        $rows = DB::table('dispensation_lines')
            ->join('dispensations', 'dispensation_lines.dispensation_id', '=', 'dispensations.id')
            ->leftJoin('genetics', 'dispensation_lines.genetic_id', '=', 'genetics.id')
            ->whereIn('dispensations.location_id', $this->resolvedLocationIds())
            ->where('dispensations.status', DispensationStatus::COMPLETED->value)
            ->whereBetween('dispensations.dispensed_at', [$start, $end])
            ->groupBy('dispensation_lines.genetic_id')
            ->orderByDesc(DB::raw('SUM(dispensation_lines.grams_cg)'))
            ->get([
                DB::raw('MAX(dispensation_lines.genetic_name_snapshot) as genetica'),
                DB::raw('MAX(genetics.product_type) as product_type'),
                DB::raw('COUNT(DISTINCT dispensation_lines.dispensation_id) as tx'),
                DB::raw('SUM(dispensation_lines.grams_cg) as grams_cg'),
                DB::raw('SUM(dispensation_lines.units_dispensed) as uds'),
                DB::raw('SUM(dispensation_lines.line_total_cents) as total_cents'),
            ])
            ->map(fn (\stdClass $r): array => [
                'genetica' => (string) ($r->genetica ?? __('Sin genética')),
                // product_type as a breakdown dimension; UNIT lines also carry a unit count.
                'tipo' => $r->product_type !== null ? (ProductType::tryFrom((string) $r->product_type)?->label() ?? '—') : '—',
                'tx' => (int) $r->tx,
                'grams' => (int) $r->grams_cg,
                'uds' => (int) $r->uds,
                'total' => (int) $r->total_cents,
            ])->all();

        return new ReportTable(
            key: 'genetics',
            title: __('Dispensado por genética'),
            columns: [
                ReportColumn::text('genetica', __('Genética'), sortable: false),
                ReportColumn::text('tipo', __('Tipo'), sortable: false),
                ReportColumn::number('tx', __('Tx')),
                ReportColumn::weight('grams', __('Dispensado')),
                ReportColumn::number('uds', __('Uds')),
                ReportColumn::money('total', __('Aportación')),
            ],
            rows: $rows,
            totals: [
                'tx' => array_sum(array_column($rows, 'tx')),
                'grams' => array_sum(array_column($rows, 'grams')),
                'uds' => array_sum(array_column($rows, 'uds')),
                'total' => array_sum(array_column($rows, 'total')),
            ],
            empty: __('Nada dispensado en este período'),
        );
    }

    // --- Over-limit members (this calendar month) -----------------------------------

    private function overLimit(): ReportTable
    {
        [$start, $end] = Period::thisMonth()->bounds();

        // One grouped query of this month's grams per member across the scope.
        $used = DB::table('dispensation_lines')
            ->join('dispensations', 'dispensation_lines.dispensation_id', '=', 'dispensations.id')
            ->whereIn('dispensations.location_id', $this->resolvedLocationIds())
            ->where('dispensations.status', DispensationStatus::COMPLETED->value)
            ->whereBetween('dispensations.dispensed_at', [$start, $end])
            ->groupBy('dispensations.member_id')
            ->pluck(DB::raw('SUM(dispensation_lines.grams_cg) as agg'), 'dispensations.member_id');

        $members = Member::query()->withoutGlobalScopes()
            ->where('organisation_id', $this->organisationId)
            ->whereIn('id', $used->keys()->all())
            ->whereNotNull('monthly_limit_cg')->where('monthly_limit_cg', '>', 0)
            ->get(['id', 'member_no', 'first_name', 'last_name', 'monthly_limit_cg']);

        $rows = [];
        foreach ($members as $member) {
            $usedCg = (int) ($used[$member->id] ?? 0);
            $limit = (int) $member->monthly_limit_cg;
            if ($usedCg >= $limit) {
                $rows[] = [
                    'member_no' => (string) $member->member_no,
                    'socio' => $member->fullName(),
                    'limite' => $limit,
                    'usado' => $usedCg,
                    'exceso' => $usedCg - $limit,
                ];
            }
        }
        usort($rows, fn (array $a, array $b): int => $b['exceso'] <=> $a['exceso']);
        $this->overLimitCount = count($rows);

        return new ReportTable(
            key: 'over_limit',
            title: __('Socios sobre el límite mensual'),
            columns: [
                ReportColumn::text('member_no', __('Nº'), sortable: false),
                ReportColumn::text('socio', __('Socio'), sortable: false),
                ReportColumn::weight('limite', __('Límite'), total: false),
                ReportColumn::weight('usado', __('Usado')),
                ReportColumn::weight('exceso', __('Exceso')),
            ],
            rows: $rows,
            totals: ['usado' => array_sum(array_column($rows, 'usado')), 'exceso' => array_sum(array_column($rows, 'exceso'))],
            empty: __('Ningún socio ha superado su límite este mes'),
            note: __('Referido al mes natural en curso, no al período seleccionado.'),
        );
    }

    // --- Limit-override log (audited) -----------------------------------------------

    private function overrideLog(): ReportTable
    {
        [$start, $end] = $this->bounds();

        $entries = DB::table('audit_logs')
            ->where('organisation_id', $this->organisationId)
            ->where('action', 'dispensation.limit.override')
            ->whereBetween('created_at', [$start, $end])
            ->orderByDesc('created_at')
            ->get(['created_at', 'auditable_id', 'after']);

        $decoded = $entries->map(function (\stdClass $e): array {
            /** @var array<string, mixed> $after */
            $after = json_decode((string) $e->after, true) ?: [];

            return [
                'created_at' => $e->created_at,
                'member_id' => $e->auditable_id,
                'location_id' => data_get($after, 'location_id'),
                'rule' => (string) data_get($after, 'rule', ''),
                'authorised_by' => data_get($after, 'authorised_by'),
                'reason' => (string) data_get($after, 'reason', ''),
            ];
        })->filter(fn (array $r): bool => $this->includesAllLocations() || in_array($r['location_id'], $this->resolvedLocationIds(), true))
            ->values();

        $memberNos = Member::query()->withoutGlobalScopes()
            ->whereIn('id', $decoded->pluck('member_id')->filter()->all())
            ->get(['id', 'member_no', 'first_name', 'last_name'])
            ->keyBy('id');
        $userNames = DB::table('users')->whereIn('id', $decoded->pluck('authorised_by')->filter()->all())->pluck('name', 'id');

        $ruleLabels = [
            'daily_limit' => __('Límite diario'),
            'monthly_limit' => __('Límite mensual'),
            'carencia' => __('Carencia'),
        ];

        $rows = $decoded->map(fn (array $r): array => [
            'created_at' => $r['created_at'],
            'socio' => ($m = $memberNos->get($r['member_id'])) !== null ? $m->member_no.' · '.$m->fullName() : '—',
            'autorizado' => $userNames[$r['authorised_by']] ?? '—',
            'regla' => $ruleLabels[$r['rule']] ?? $r['rule'],
            'motivo' => $r['reason'],
        ])->all();

        return new ReportTable(
            key: 'overrides',
            title: __('Registro de excepciones de límite'),
            columns: [
                ReportColumn::datetime('created_at', __('Fecha'), sortable: false),
                ReportColumn::text('socio', __('Socio'), sortable: false),
                ReportColumn::text('autorizado', __('Autorizado por'), sortable: false),
                ReportColumn::text('regla', __('Regla'), sortable: false),
                ReportColumn::text('motivo', __('Motivo'), sortable: false),
            ],
            rows: $rows,
            empty: __('Sin excepciones de límite en este período'),
        );
    }
}
