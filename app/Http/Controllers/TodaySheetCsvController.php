<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Support\CounterOperator;
use App\Support\CounterTerminals;
use App\Support\NumberFormat;
use App\ViewModels\CounterDaySheet;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Prompt 371 — «Descargar (CSV)» on the counter's day sheet: the same rows and totals as the screen ({@see CounterDaySheet}),
 * for the counter's sede, for a PIN operator holding `reports.export`. Amounts only with `reports.view`, as on the screen.
 * Numbers in the one display rule (316): a decimal point, no grouping.
 */
class TodaySheetCsvController
{
    public function __invoke(): StreamedResponse
    {
        $operator = CounterOperator::current();
        abort_unless($operator !== null && $operator->can('reports.export'), 403);

        $chosen = session('counter.location_id');
        $location = CounterTerminals::availableSedes($operator)->first(fn (Location $l): bool => $l->id === $chosen);
        abort_if($location === null, 403);

        $sheet = new CounterDaySheet($location);
        $money = $operator->can('reports.view');
        $euros = fn (int $cents): string => NumberFormat::decimal($cents / 100, 2);

        $csv = Writer::createFromString();
        $csv->insertOne(array_merge([__('Hora'), __('Tipo'), __('Socio'), __('Qué'), __('Atendió'), __('Estado')],
            $money ? [__('Importe'), __('efectivo'), __('monedero'), __('cuenta')] : []));
        foreach ($sheet->rows() as $row) {
            $csv->insertOne(array_merge([
                $row['time'],
                $row['kind'] === 'bar' ? __('Barra') : __('Dispensario'),
                $row['member_no'] !== '' ? $row['member_no'].' '.$row['member_name'] : __('Sin socio'),
                implode(' · ', $row['what']),
                $row['operator_name'],
                $row['voided'] ? trim(__('Anulada').' '.($row['void_reason'] ?? '')) : '',
            ], $money ? [$euros($row['money']['total']), $euros($row['money']['cash']), $euros($row['money']['wallet']), $euros($row['money']['tab'])] : []));
        }

        $totals = $sheet->totals();
        $csv->insertOne([]);
        $csv->insertOne([__('Total'), __('Operaciones'), (string) $totals['count']]);
        foreach ($totals['strains'] as $strain) {
            $csv->insertOne([__('Total'), $strain['name'], NumberFormat::decimal($strain['grams_cg'] / 100, 2).' g', __('se cobra :grams', ['grams' => NumberFormat::decimal($strain['charged_cg'] / 100, 2).' g'])]);
        }
        foreach ($totals['units'] as $unit) {
            $csv->insertOne([__('Total'), $unit['name'], (string) $unit['units']]);
        }
        $csv->insertOne([__('Total'), __('Barra y tienda: productos'), (string) $totals['bar_items']]);
        if ($money) {
            $csv->insertOne([__('Total'), __('Importe'), $euros($totals['money']['total']), __('efectivo').' '.$euros($totals['money']['cash']),
                __('monedero').' '.$euros($totals['money']['wallet']), __('cuenta').' '.$euros($totals['money']['tab'])]);
        }

        $content = $csv->toString();
        $filename = 'hoja-del-dia-'.$sheet->period()->start->setTimezone($location->timezone ?: 'Europe/Madrid')->format('Y-m-d').'.csv';

        return response()->streamDownload(fn () => print ($content), $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
