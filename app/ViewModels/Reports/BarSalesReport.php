<?php

namespace App\ViewModels\Reports;

use App\Enums\OrderStatus;
use App\Models\Location;
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
        $locationIds = $this->resolvedLocationIds();

        // Articles are PER-LOCATION (two sedes each have their own "Agua"), so at a multi-location scope
        // the article name alone can't tell the rows apart (prompt 69). Surface the sede — grouping stays
        // by article_id (correct: two same-named articles genuinely are two things), this only labels it.
        $showSede = count($locationIds) > 1;
        $locationNames = Location::query()->withoutGlobalScopes()->whereIn('id', $locationIds)->pluck('name', 'id');

        $orders = DB::table('orders')
            ->whereIn('location_id', $locationIds)
            ->where('status', OrderStatus::COMPLETED->value)
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->get(['items', 'location_id']);

        /** @var array<string, array{articulo: string, sede: string, unidades: int, importe: int}> $agg */
        $agg = [];

        foreach ($orders as $order) {
            $items = json_decode((string) $order->items, true);

            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                // Group by article; off-catalogue manual lines (article_id null) group by their name
                // across sedes (they carry no location-bound article), so every euro lands in one row and
                // the totals reconcile. An article's id is location-scoped, so its sede is consistent.
                $articleId = $item['article_id'] ?? null;
                $name = (string) ($item['name'] ?? __('Sin nombre'));
                $groupKey = $articleId !== null ? 'a:'.$articleId : 'm:'.$name;
                $sede = $articleId !== null ? (string) ($locationNames[$order->location_id] ?? '—') : '—';

                // Flag off-catalogue manual lines so an owner can pick out exactly the ones that bypass the
                // catalogue and its prices — the whole reason a reason is required on them (prompt 126).
                $agg[$groupKey] ??= ['articulo' => $name, 'sede' => $sede, 'unidades' => 0, 'importe' => 0, 'manual' => $articleId === null];
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

        $columns = [ReportColumn::text('articulo', __('Artículo'), sortable: false)];
        if ($showSede) {
            $columns[] = ReportColumn::text('sede', __('Sede'), sortable: false);
        }
        $columns[] = ReportColumn::number('unidades', __('Unidades'));
        $columns[] = ReportColumn::money('importe', __('Importe'));

        return new ReportTable(
            key: 'articulos',
            title: __('Barra y tienda por artículo'),
            columns: $columns,
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
