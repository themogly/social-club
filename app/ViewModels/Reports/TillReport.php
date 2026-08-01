<?php

namespace App\ViewModels\Reports;

use App\Enums\CashMovementType;
use App\Enums\TillSessionStatus;
use App\Models\TillSession;
use App\Support\Money;
use App\Support\ZReport;
use Illuminate\Support\Facades\DB;

/**
 * Cajas — the session Z-reports (`App\Support\ZReport`, the same breakdown printed at
 * close), variances by session and by operator, and the cash movements. The per-session
 * figures are the aggregator's own, so the report's descuadre equals the drawer's.
 */
class TillReport extends AbstractReport
{
    private int $totalVariance = 0;

    private int $totalExpected = 0;

    private int $sessionCount = 0;

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
        ];
    }

    public function summary(): array
    {
        $this->tables();

        return [
            ['label' => __('Sesiones'), 'value' => (string) $this->sessionCount],
            ['label' => __('Efectivo esperado'), 'value' => Money::fromCents($this->totalExpected)->formatted()],
            ['label' => __('Descuadre total'), 'value' => Money::fromCents($this->totalVariance)->formatted(), 'tone' => $this->totalVariance === 0 ? 'success' : 'warning'],
        ];
    }

    // --- Session Z-reports ----------------------------------------------------------

    private function sessions(): ReportTable
    {
        [$start, $end] = $this->bounds();

        $sessions = TillSession::query()->withoutGlobalScopes()
            ->with('openedBy')
            ->whereIn('location_id', $this->resolvedLocationIds())
            ->where('opened_at', '>=', $start)->where('opened_at', '<', $end)
            ->orderByDesc('opened_at')
            ->get();

        $this->sessionCount = $sessions->count();

        $rows = [];
        $totals = ['float' => 0, 'expected' => 0, 'counted' => 0, 'variance' => 0, 'tx' => 0];
        foreach ($sessions as $session) {
            $z = ZReport::for($session);
            $rows[] = [
                'sort' => $session->opened_at?->getTimestamp() ?? 0,
                'fecha' => $session->opened_at,
                'operador' => $z['operator'] ?? '—',
                'terminal' => $session->terminal ?? '—',
                'float' => (int) $z['float'],
                'expected' => (int) $z['expected'],
                'counted' => $z['counted'],
                'variance' => $z['variance'],
                'tx' => (int) $z['transaction_count'],
                'estado' => $session->status === TillSessionStatus::OPEN
                    ? __('Abierta')
                    // A closed session whose ledger moved after cierre (a post-close void) is flagged, so the
                    // frozen cash-up is not mistaken for the current state (prompt 103).
                    : ($z['post_close_adjusted'] ? __('Cerrada (ajustada tras el cierre)') : __('Cerrada')),
                'operator_key' => $session->opened_by ?? 'none',
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
                ReportColumn::text('operador', __('Operador')),
                ReportColumn::text('terminal', __('Terminal')),
                ReportColumn::money('float', __('Fondo')),
                ReportColumn::money('expected', __('Esperado')),
                ReportColumn::money('counted', __('Contado')),
                ReportColumn::money('variance', __('Descuadre')),
                ReportColumn::number('tx', __('Tx')),
                ReportColumn::text('estado', __('Estado')),
            ],
            rows: $rows,
            totals: $totals,
            empty: __('Sin cajas en este período'),
            emptyHint: __('Los arqueos aparecen aquí cuando se abre y cierra una caja en el mostrador.'),
            defaultSort: 'fecha',
            sortable: true,
        );
    }

    // --- Variance by operator (closed sessions) -------------------------------------

    private function varianceByOperator(ReportTable $sessions): ReportTable
    {
        /** @var array<string, array{operador: string, sesiones: int, descuadre: int}> $byOperator */
        $byOperator = [];
        foreach ($sessions->rows as $row) {
            if ($row['variance'] === null) {
                continue; // still open — no variance yet
            }
            $key = (string) $row['operator_key'];
            $byOperator[$key] ??= ['operador' => (string) $row['operador'], 'sesiones' => 0, 'descuadre' => 0];
            $byOperator[$key]['sesiones']++;
            $byOperator[$key]['descuadre'] += (int) $row['variance'];
        }

        $rows = array_values($byOperator);
        usort($rows, fn (array $a, array $b): int => abs($b['descuadre']) <=> abs($a['descuadre']));

        return new ReportTable(
            key: 'by_operator',
            title: __('Descuadre por operador'),
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
