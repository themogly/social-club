<?php

namespace App\ViewModels\Reports;

use App\Enums\OrderStatus;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Barra y tienda por artículo — the itemised layer under the "Barra" aggregate that
 * FinancialReport already shows (prompt 43). Which drinks/snacks/merch actually sold, by
 * units and by revenue, for the period + scoped locations. The grand total reconciles
 * exactly with FinancialReport's "Barra" column (both are SUM(orders.total_cents) for
 * COMPLETED orders in scope) — this is the same money at finer granularity, never a second
 * figure. Line items live in the order's JSON `items` snapshot, so aggregation is in PHP
 * (portable across the SQLite dev / MySQL prod split); every euro is integer cents.
 */
class BarSalesReport extends AbstractReport
{
    public function key(): string
    {
        return 'ventas-barra';
    }

    public function title(): string
    {
        return __('Barra y tienda por artículo');
    }

    protected function build(): array
    {
        return [$this->byArticle()];
    }

    public function summary(): array
    {
        $totals = $this->primary()->totals;

        return [
            ['label' => __('Barra y tienda'), 'value' => Money::fromCents((int) ($totals['importe'] ?? 0))->formatted()],
            ['label' => __('Unidades vendidas'), 'value' => (string) (int) ($totals['unidades'] ?? 0)],
        ];
    }

    private function byArticle(): ReportTable
    {
        [$start, $end] = $this->bounds();

        $itemsPerOrder = DB::table('orders')
            ->whereIn('location_id', $this->resolvedLocationIds())
            ->where('status', OrderStatus::COMPLETED->value)
            ->whereBetween('created_at', [$start, $end])
            ->pluck('items');

        /** @var array<string, array{articulo: string, unidades: int, importe: int}> $agg */
        $agg = [];

        foreach ($itemsPerOrder as $json) {
            $items = json_decode((string) $json, true);

            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                // Group by article; off-catalogue manual lines (article_id null) group by their name,
                // so every euro of the order total lands in exactly one row and the totals reconcile.
                $articleId = $item['article_id'] ?? null;
                $name = (string) ($item['name'] ?? __('Sin nombre'));
                $groupKey = $articleId !== null ? 'a:'.$articleId : 'm:'.$name;

                $agg[$groupKey] ??= ['articulo' => $name, 'unidades' => 0, 'importe' => 0];
                $agg[$groupKey]['unidades'] += (int) ($item['qty'] ?? 0);
                $agg[$groupKey]['importe'] += (int) ($item['line_total_cents'] ?? 0);
            }
        }

        $rows = array_values($agg);
        usort($rows, fn (array $a, array $b): int => $b['importe'] <=> $a['importe']);

        $totals = [
            'unidades' => array_sum(array_column($rows, 'unidades')),
            'importe' => array_sum(array_column($rows, 'importe')),
        ];

        return new ReportTable(
            key: 'articulos',
            title: __('Barra y tienda por artículo'),
            columns: [
                ReportColumn::text('articulo', __('Artículo'), sortable: false),
                ReportColumn::number('unidades', __('Unidades')),
                ReportColumn::money('importe', __('Importe')),
            ],
            rows: $rows,
            totals: $totals,
            empty: __('Sin ventas de barra en este período'),
            emptyHint: __('Cuando se registren ventas en la barra aparecerán aquí, por artículo.'),
            defaultSort: 'importe',
            defaultSortDir: 'desc',
            sortable: true,
        );
    }
}
