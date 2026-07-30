<?php

namespace App\ViewModels\Reports;

use App\Enums\BatchStatus;
use App\Enums\DispensationStatus;
use App\Enums\StockMovementType;
use App\Enums\StockTakeStatus;
use App\Models\Batch;
use App\Support\Money;
use App\Support\Weight;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stock — what is on hand and what it is worth, the period's movements (intake, merma,
 * adjustments), stock-take variances and the batch→dispensed traceability spine.
 * Valuation is Σ(remaining_cg × cost_per_gram / 100), integer cents — the same method
 * the dashboard's "valor del stock" card uses, so the two never disagree.
 */
class StockReport extends AbstractReport
{
    private int $valueCents = 0;

    private int $onHandCg = 0;

    private int $mermaCg = 0;

    public function key(): string
    {
        return 'stock';
    }

    public function title(): string
    {
        return __('Informe de existencias');
    }

    protected function build(): array
    {
        return [
            $this->onHand(),
            $this->movements(),
            $this->stockTakeVariances(),
        ];
    }

    public function summary(): array
    {
        $this->tables();

        return [
            ['label' => __('Valor del stock'), 'value' => Money::fromCents($this->valueCents)->formatted()],
            ['label' => __('Existencias'), 'value' => Weight::fromCentigrams($this->onHandCg)->formatted()],
            ['label' => __('Merma'), 'value' => Weight::fromCentigrams($this->mermaCg)->formatted(), 'tone' => $this->mermaCg > 0 ? 'warning' : 'success'],
            ['label' => __('Días de inventario'), 'value' => $this->daysOfInventory() ?? '—'],
        ];
    }

    // --- On-hand by batch (valuation + traceability) --------------------------------

    private function onHand(): ReportTable
    {
        $batches = DB::table('batches')
            ->whereIn('location_id', $this->resolvedLocationIds())
            ->where('status', BatchStatus::OPEN->value)
            ->whereNull('deleted_at')
            ->get(['id', 'batch_no', 'genetic_id', 'remaining_cg', 'cost_per_gram_cents', 'expires_on']);

        $names = DB::table('genetics')->whereIn('id', $batches->pluck('genetic_id')->unique()->all())->pluck('name', 'id');
        $dispensed = DB::table('dispensation_lines')
            ->whereIn('batch_id', $batches->pluck('id')->all())
            ->groupBy('batch_id')
            ->pluck(DB::raw('SUM(grams_cg) as agg'), 'batch_id');

        $rows = $batches->map(function (\stdClass $b) use ($names, $dispensed): array {
            $remaining = (int) $b->remaining_cg;
            $rate = (int) $b->cost_per_gram_cents;
            $value = intdiv($remaining * $rate, 100);
            $this->valueCents += $value;
            $this->onHandCg += $remaining;

            return [
                'lote' => (string) $b->batch_no,
                'genetica' => (string) ($names[$b->genetic_id] ?? __('Sin genética')),
                'restante' => $remaining,
                'coste_g' => $rate,
                'valor' => $value,
                'dispensado' => (int) ($dispensed[$b->id] ?? 0),
                'caduca' => $b->expires_on,
            ];
        })->sortByDesc('valor')->values()->all();

        return new ReportTable(
            key: 'on_hand',
            title: __('Existencias por lote'),
            columns: [
                ReportColumn::text('lote', __('Lote')),
                ReportColumn::text('genetica', __('Genética')),
                ReportColumn::weight('restante', __('Restante')),
                ReportColumn::money('coste_g', __('Coste/g'), total: false),
                ReportColumn::money('valor', __('Valor')),
                ReportColumn::weight('dispensado', __('Dispensado')),
                ReportColumn::date('caduca', __('Caduca')),
            ],
            rows: $rows,
            totals: [
                'restante' => $this->onHandCg,
                'valor' => $this->valueCents,
                'dispensado' => array_sum(array_column($rows, 'dispensado')),
            ],
            empty: __('Sin lotes abiertos'),
            emptyHint: __('Registra una entrada de lote para ver aquí las existencias y su valoración.'),
            defaultSort: 'valor',
            sortable: true,
        );
    }

    // --- Movements by type (period) -------------------------------------------------

    private function movements(): ReportTable
    {
        [$start, $end] = $this->bounds();

        $byType = DB::table('stock_movements')
            ->whereIn('location_id', $this->resolvedLocationIds())
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('type')
            ->get([
                'type',
                DB::raw('COUNT(*) as movimientos'),
                DB::raw('SUM(qty_cg) as grams_cg'),
            ]);

        $labels = [
            StockMovementType::INTAKE->value => __('Entrada'),
            StockMovementType::DISPENSE->value => __('Dispensación'),
            StockMovementType::SALE->value => __('Venta barra'),
            StockMovementType::ADJUSTMENT->value => __('Ajuste'),
            StockMovementType::MERMA->value => __('Merma'),
            StockMovementType::TRANSFER_IN->value => __('Traspaso entrada'),
            StockMovementType::TRANSFER_OUT->value => __('Traspaso salida'),
        ];

        $rows = $byType->map(function (\stdClass $r) use ($labels): array {
            if ($r->type === StockMovementType::MERMA->value) {
                $this->mermaCg += abs((int) $r->grams_cg);
            }

            return [
                'tipo' => $labels[$r->type] ?? $r->type,
                'movimientos' => (int) $r->movimientos,
                'grams' => (int) $r->grams_cg,
            ];
        })->all();

        return new ReportTable(
            key: 'movements',
            title: __('Movimientos por tipo'),
            columns: [
                ReportColumn::text('tipo', __('Tipo'), sortable: false),
                ReportColumn::number('movimientos', __('Movimientos')),
                ReportColumn::weight('grams', __('Gramos')),
            ],
            rows: $rows,
            totals: [
                'movimientos' => array_sum(array_column($rows, 'movimientos')),
                'grams' => array_sum(array_column($rows, 'grams')),
            ],
            empty: __('Sin movimientos de stock en este período'),
        );
    }

    // --- Stock-take variances (committed in period) ---------------------------------

    private function stockTakeVariances(): ReportTable
    {
        [$start, $end] = $this->bounds();

        $lines = DB::table('stock_take_lines')
            ->join('stock_takes', 'stock_take_lines.stock_take_id', '=', 'stock_takes.id')
            ->whereIn('stock_takes.location_id', $this->resolvedLocationIds())
            ->where('stock_takes.status', StockTakeStatus::COMMITTED->value)
            ->whereBetween('stock_takes.committed_at', [$start, $end])
            ->where('stock_take_lines.countable_type', (new Batch)->getMorphClass())
            ->get([
                'stock_take_lines.countable_id as batch_id',
                'stock_take_lines.expected_cg as expected_cg',
                'stock_take_lines.counted_cg as counted_cg',
                'stock_take_lines.variance_cg as variance_cg',
            ]);

        $batchNos = DB::table('batches')->whereIn('id', $lines->pluck('batch_id')->all())->pluck('batch_no', 'id');

        $rows = $lines->map(fn (\stdClass $r): array => [
            'lote' => (string) ($batchNos[$r->batch_id] ?? '—'),
            'esperado' => (int) $r->expected_cg,
            'contado' => (int) $r->counted_cg,
            'variacion' => (int) $r->variance_cg,
        ])->all();

        return new ReportTable(
            key: 'stock_takes',
            title: __('Descuadres de recuento'),
            columns: [
                ReportColumn::text('lote', __('Lote'), sortable: false),
                ReportColumn::weight('esperado', __('Esperado'), total: false),
                ReportColumn::weight('contado', __('Contado'), total: false),
                ReportColumn::weight('variacion', __('Variación')),
            ],
            rows: $rows,
            totals: ['variacion' => array_sum(array_column($rows, 'variacion'))],
            empty: __('Sin recuentos cerrados en este período'),
        );
    }

    private function daysOfInventory(): ?string
    {
        if ($this->onHandCg <= 0) {
            return '0';
        }

        $end = CarbonImmutable::now();
        $start = $end->subDays(30);
        $trailing = (int) DB::table('dispensation_lines')
            ->join('dispensations', 'dispensation_lines.dispensation_id', '=', 'dispensations.id')
            ->whereIn('dispensations.location_id', $this->resolvedLocationIds())
            ->where('dispensations.status', DispensationStatus::COMPLETED->value)
            ->whereBetween('dispensations.dispensed_at', [$start, $end])
            ->sum('dispensation_lines.grams_cg');

        $perDay = intdiv($trailing, 30);

        return $perDay <= 0 ? null : (string) intdiv($this->onHandCg, $perDay);
    }
}
