<?php

namespace App\ViewModels\Reports;

use App\Enums\CashMovementType;
use App\Enums\CashPot;
use App\Enums\TillSessionStatus;
use App\Filament\Resources\TillSessions\TillSessionResource;
use App\Models\TillSession;
use App\Support\Money;
use App\Support\TillSummary;
use App\Support\ZReport;
use Illuminate\Support\Facades\DB;

/**
 * Cajas — the session Z-reports (`App\Support\ZReport`, the same breakdown printed at
 * close), variances by session and by operator, and the cash movements. The per-session
 * figures are the aggregator's own, so the report's descuadre equals the drawer's.
 *
 * Prompt 366 — a close is never refused for a difference, so this is where the owner finds them: «Solo con diferencia»
 * keeps the closes beyond the tolerance in force at each close, «Sin explicar» (amber) marks the ones with no note, and
 * each row opens its till page.
 */
class TillReport extends AbstractReport
{
    private int $totalVariance = 0;

    private int $totalExpected = 0;

    private int $sessionCount = 0;

    private bool $onlyWithVariance = false;

    private int $beyondCount = 0;

    private int $unexplainedCount = 0;

    /** «Solo con diferencia» — only the closes beyond the tolerance. */
    public function onlyWithVariance(bool $on = true): static
    {
        $this->onlyWithVariance = $on;

        return $this;
    }

    public function key(): string
    {
        return 'cajas';
    }

    public function title(): string
    {
        return __('Informe de cajas');
    }

    protected function build(): array
    {
        return [
            $sessions = $this->sessions(),
            $this->varianceByOperator($sessions),
            $this->cashMovements(),
            $this->pettyCashItems(),
        ];
    }

    public function summary(): array
    {
        $this->tables();

        return [
            ['label' => __('Sesiones'), 'value' => (string) $this->sessionCount],
            ['label' => __('Efectivo esperado'), 'value' => Money::fromCents($this->totalExpected)->formatted()],
            ['label' => __('Descuadre total'), 'value' => Money::fromCents($this->totalVariance)->formatted(), 'tone' => $this->totalVariance === 0 ? 'success' : 'warning'],
            ['label' => __('Cierres con diferencia'), 'value' => (string) $this->beyondCount, 'tone' => $this->beyondCount === 0 ? 'success' : 'warning'],
            ['label' => __('Sin explicar'), 'value' => (string) $this->unexplainedCount, 'tone' => $this->unexplainedCount === 0 ? 'success' : 'warning'],
        ];
    }

    // --- Session Z-reports ----------------------------------------------------------

    private function sessions(): ReportTable
    {
        [$start, $end] = $this->bounds();

        $sessions = TillSession::query()->withoutGlobalScopes()
            ->with(['openedBy', 'closedBy'])
            ->whereIn('location_id', $this->resolvedLocationIds())
            ->where('opened_at', '>=', $start)->where('opened_at', '<', $end)
            ->orderByDesc('opened_at')
            ->get();

        $this->beyondCount = $sessions->filter(fn (TillSession $session): bool => $session->closedBeyondTolerance())->count();
        $this->unexplainedCount = $sessions->filter(fn (TillSession $session): bool => $session->closedUnexplained())->count();
        if ($this->onlyWithVariance) {
            $sessions = $sessions->filter(fn (TillSession $session): bool => $session->closedBeyondTolerance())->values();
        }

        $this->sessionCount = $sessions->count();
        // Prompt 349 / 373 — a box's columns appear only when a session in view kept it.
        $potKeys = [CashPot::BAR->value => 'barra', CashPot::FEES->value => 'cuotas', CashPot::EDIBLES->value => 'comestibles'];
        $anyBox = collect(CashPot::optional())->mapWithKeys(fn (CashPot $pot): array => [$pot->value => $sessions->contains(fn (TillSession $session): bool => $session->hasOwnBox($pot))])->all();

        // One batched Z-report for the whole period — a fixed number of grouped queries, not ~12 per session
        // in this loop (prompt 108).
        $zBySession = ZReport::forMany($sessions);

        $rows = [];
        $totals = ['float' => 0, 'expected' => 0, 'counted' => 0, 'variance' => 0, 'tx' => 0];
        foreach ($sessions as $session) {
            $z = $zBySession[$session->id];
            $rows[] = [
                'sort' => $session->opened_at?->getTimestamp() ?? 0,
                'fecha' => $session->opened_at,
                'fecha__url' => TillSessionResource::getUrl('view', ['record' => $session]),
                // Prompt 369 — who opened AND who counted: the difference is the shift's, but the owner asks whoever did the
                // arqueo, so «Descuadre por quien hizo el arqueo» groups by the closer.
                'operador' => $session->closed_by !== null
                    ? __('Abrió: :opened · Cerró: :closed', ['opened' => $z['operator'] ?? '—', 'closed' => $session->closedBy->name ?? '—'])
                    : __('Abrió: :opened', ['opened' => $z['operator'] ?? '—']),
                'closer' => $session->closedBy->name ?? '—',
                'terminal' => $session->terminal ?? '—',
                'float' => (int) $z['float'],
                'expected' => (int) $z['expected'],
                'counted' => $z['counted'],
                'variance' => $z['variance'],
                'tx' => (int) $z['transaction_count'],
                // Prompt 349 — the bar and fees pots, their own columns (the three above are the dispensary pot's then).
            ] + $this->boxFigures($session, $z, $potKeys) + [
                'estado' => $session->status === TillSessionStatus::OPEN
                    ? __('Abierta')
                    // A closed session whose ledger moved after cierre (a post-close void) is flagged, so the
                    // frozen cash-up is not mistaken for the current state (prompt 103).
                    : ($z['post_close_adjusted'] ? __('Cerrada (ajustada tras el cierre)') : __('Cerrada')),
                'sin_explicar' => $session->closedUnexplained() ? __('Sin explicar') : '',
                'sin_explicar__tone' => 'warning',
                'operator_key' => $session->closed_by ?? 'none',
            ];
            $totals['float'] += (int) $z['float'];
            // The totals row reconciles the ARQUEO — the closed sessions (prompt 103): expected, counted and
            // variance sum over the same set, so Σcounted − Σexpected === Σvariance holds. An OPEN session has
            // no arqueo yet (counted/variance null); its live expected is shown per row but excluded here, so
            // an in-progress drawer cannot make the reconciliation total contradict itself.
            if ($z['counted'] !== null) {
                $totals['expected'] += (int) $z['expected'];
                $totals['counted'] += (int) $z['counted'];
                $totals['variance'] += (int) $z['variance'];
            }
            $totals['tx'] += (int) $z['transaction_count'];
        }

        $this->totalVariance = $totals['variance'];
        $this->totalExpected = $totals['expected'];

        return new ReportTable(
            key: 'sessions',
            title: __('Arqueos de caja'),
            columns: [
                ReportColumn::datetime('fecha', __('Apertura')),
                ReportColumn::text('operador', __('Abrió / cerró')),
                ReportColumn::text('terminal', __('Terminal')),
                ReportColumn::money('float', __('Fondo')),
                ReportColumn::money('expected', __('Esperado')),
                ReportColumn::money('counted', __('Contado')),
                ReportColumn::money('variance', __('Descuadre')),
                // Prompt 366 — beside the variance, not after the pot columns, so it is on screen whatever the club's pots.
                ReportColumn::text('sin_explicar', __('Sin explicar')),
                ReportColumn::number('tx', __('Tx')),
                ...$this->boxColumns($anyBox),
                ReportColumn::text('estado', __('Estado')),
            ],
            rows: $rows,
            totals: $totals,
            empty: $this->onlyWithVariance ? __('Ningún cierre con diferencia en este período') : __('Sin cajas en este período'),
            emptyHint: __('Los arqueos aparecen aquí cuando se abre y cierra una caja en el mostrador.'),
            defaultSort: 'fecha',
            sortable: true,
        );
    }

    /**
     * Prompt 349 / 373 — each own box's expected / counted / difference for a row; "—" where the session had no such box.
     *
     * @param  array<string, mixed>  $z
     * @param  array<string, string>  $potKeys
     * @return array<string, mixed>
     */
    private function boxFigures(TillSession $session, array $z, array $potKeys): array
    {
        $out = [];
        foreach (CashPot::optional() as $pot) {
            $key = $potKeys[$pot->value];
            $col = $pot->column();
            $own = $session->hasOwnBox($pot);
            $out[$key.'_esperado'] = $own ? (int) $z[$col.'_expected'] : null;
            $out[$key.'_contado'] = $own ? ($z[$col.'_counted'] !== null ? Money::fromCents((int) $z[$col.'_counted'])->formatted() : __('no contado')) : '—';
            $out[$key.'_descuadre'] = $own ? $z[$col.'_variance'] : null;
        }

        return $out;
    }

    /**
     * @param  array<string, bool>  $anyBox
     * @return list<ReportColumn>
     */
    private function boxColumns(array $anyBox): array
    {
        $columns = [];
        foreach ([[CashPot::BAR, 'barra', __('Barra')], [CashPot::FEES, 'cuotas', __('Cuotas')], [CashPot::EDIBLES, 'comestibles', __('Comestibles')]] as [$pot, $key, $label]) {
            if ($anyBox[$pot->value] ?? false) {
                array_push($columns,
                    ReportColumn::money($key.'_esperado', __(':pot: esperado', ['pot' => $label])),
                    ReportColumn::text($key.'_contado', __(':pot: contado', ['pot' => $label])),
                    ReportColumn::money($key.'_descuadre', __(':pot: descuadre', ['pot' => $label])),
                );
            }
        }

        return $columns;
    }

    // --- Variance by who counted (closed sessions; prompt 369) -----------------------

    private function varianceByOperator(ReportTable $sessions): ReportTable
    {
        /** @var array<string, array{operador: string, sesiones: int, descuadre: int}> $byOperator */
        $byOperator = [];
        foreach ($sessions->rows as $row) {
            if ($row['variance'] === null) {
                continue; // still open — no variance yet
            }
            $key = (string) $row['operator_key'];
            $byOperator[$key] ??= ['operador' => (string) $row['closer'], 'sesiones' => 0, 'descuadre' => 0];
            $byOperator[$key]['sesiones']++;
            $byOperator[$key]['descuadre'] += (int) $row['variance'];
        }

        $rows = array_values($byOperator);
        usort($rows, fn (array $a, array $b): int => abs($b['descuadre']) <=> abs($a['descuadre']));

        return new ReportTable(
            key: 'by_operator',
            title: __('Descuadre por quien hizo el arqueo'),
            columns: [
                ReportColumn::text('operador', __('Operador'), sortable: false),
                ReportColumn::number('sesiones', __('Sesiones')),
                ReportColumn::money('descuadre', __('Descuadre')),
            ],
            rows: $rows,
            totals: [
                'sesiones' => array_sum(array_column($rows, 'sesiones')),
                'descuadre' => array_sum(array_column($rows, 'descuadre')),
            ],
            empty: __('Sin cajas cerradas en este período'),
        );
    }

    // --- Cash movements by type -----------------------------------------------------

    /**
     * Prompt 265 — each till expense of the period: what the petty cash was spent ON, not one total. From the same
     * source as every other view (`TillSummary::breakdownMany`), so the report and the arqueo can never disagree.
     */
    private function pettyCashItems(): ReportTable
    {
        [$start, $end] = $this->bounds();

        $sessions = TillSession::query()->withoutGlobalScopes()
            ->whereIn('location_id', $this->resolvedLocationIds())
            ->where('opened_at', '>=', $start)->where('opened_at', '<', $end)
            ->orderByDesc('opened_at')
            ->get();

        $rows = [];
        foreach (TillSummary::breakdownMany($sessions) as $sessionId => $breakdown) {
            $session = $sessions->firstWhere('id', $sessionId);
            foreach ($breakdown['petty_cash_items'] as $item) {
                $rows[] = [
                    'fecha' => $session?->opened_at,
                    'terminal' => $session->terminal ?? '—',
                    'categoria' => $item['category'],
                    'nota' => $item['note'] ?? '—',
                    'registrado_por' => $item['recorded_by'].' · '.$item['at'],
                    'importe' => $item['amount_cents'],
                ];
            }
        }

        return new ReportTable(
            key: 'petty_cash_items',
            title: __('Detalle de caja chica'),
            columns: [
                ReportColumn::datetime('fecha', __('Caja')),
                ReportColumn::text('terminal', __('Terminal')),
                ReportColumn::text('categoria', __('Categoría')),
                ReportColumn::text('nota', __('Concepto')),
                ReportColumn::text('registrado_por', __('Registrado por')),
                ReportColumn::money('importe', __('Importe')),
            ],
            rows: $rows,
            totals: ['importe' => array_sum(array_column($rows, 'importe'))],
            empty: __('Sin gastos de caja en este período'),
        );
    }

    private function cashMovements(): ReportTable
    {
        [$start, $end] = $this->bounds();

        $byType = DB::table('cash_movements')
            ->join('till_sessions', 'cash_movements.till_session_id', '=', 'till_sessions.id')
            ->whereIn('till_sessions.location_id', $this->resolvedLocationIds())
            ->where('till_sessions.opened_at', '>=', $start)->where('till_sessions.opened_at', '<', $end)
            ->groupBy('cash_movements.type')
            ->get([
                'cash_movements.type as type',
                DB::raw('COUNT(*) as movimientos'),
                DB::raw('SUM(cash_movements.amount_cents) as importe'),
            ]);

        $labels = [
            CashMovementType::IN->value => __('Entrada'),
            CashMovementType::OUT->value => __('Salida'),
            CashMovementType::BANKED->value => __('Ingresado en banco'),
            CashMovementType::PETTY_CASH->value => __('Caja chica'),
        ];

        $rows = $byType->map(fn (\stdClass $r): array => [
            'tipo' => $labels[$r->type] ?? $r->type,
            'movimientos' => (int) $r->movimientos,
            'importe' => (int) $r->importe,
        ])->all();

        return new ReportTable(
            key: 'cash_movements',
            title: __('Movimientos de efectivo'),
            columns: [
                ReportColumn::text('tipo', __('Tipo'), sortable: false),
                ReportColumn::number('movimientos', __('Movimientos')),
                ReportColumn::money('importe', __('Importe')),
            ],
            rows: $rows,
            totals: [
                'movimientos' => array_sum(array_column($rows, 'movimientos')),
                'importe' => array_sum(array_column($rows, 'importe')),
            ],
            empty: __('Sin movimientos de efectivo en este período'),
        );
    }
}
