<?php

namespace App\Support\Spreadsheet;

use App\Support\OrganisationIdentity;
use App\Support\Period;
use App\ViewModels\Reports\ReportColumn;
use App\ViewModels\Reports\ReportTable;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use League\Csv\Writer;

/**
 * Turns a report's shared {@see ReportTable} shape into a downloadable file. It never
 * re-derives a figure — it renders the SAME columns, rows and totals the screen shows,
 * so an exported total equals the on-screen total to the cent by construction.
 *
 * CSV is locale-aware ("Excel-flavoured"): a UTF-8 BOM so accents survive, `;` + `,`
 * decimal under Spanish (what a Spanish Excel expects), `,` + `.` otherwise. Money and
 * weight are written as bare, summable numbers — no currency symbol, no thousands
 * separators — so the file both opens cleanly and re-parses to the exact stored integer.
 * A dedicated XLSX writer (PhpSpreadsheet) is deliberately NOT bundled; this is a CSV
 * that opens in Excel.
 */
class ReportExport
{
    /** UTF-8 byte-order mark so Excel reads accented Spanish correctly. */
    private const BOM = "\xEF\xBB\xBF";

    public static function csv(ReportTable $table): string
    {
        [$delimiter, $decimal] = self::localeCsvFormat();

        $writer = Writer::createFromString();
        $writer->setDelimiter($delimiter);

        $writer->insertOne(array_map(fn (ReportColumn $c): string => $c->label, $table->columns));

        foreach ($table->rows as $row) {
            $writer->insertOne(array_map(
                fn (ReportColumn $c): string => $c->export($row[$c->key] ?? null, $decimal),
                $table->columns,
            ));
        }

        if ($table->hasTotals()) {
            $writer->insertOne(self::totalsRow($table, $decimal));
        }

        return self::BOM.$writer->toString();
    }

    /**
     * A full report as a PDF (title, summary strip and every table with its totals).
     *
     * @param  list<array{label: string, value: string, tone?: string}>  $summary
     * @param  list<ReportTable>  $tables
     */
    public static function pdf(string $title, array $summary, array $tables, string $scopeLabel, string $periodLabel): string
    {
        $identity = OrganisationIdentity::current();

        return Pdf::loadView('reports.pdf', [
            'title' => $title,
            'summary' => $summary,
            'tables' => $tables,
            'scopeLabel' => $scopeLabel,
            'periodLabel' => $periodLabel,
            'generatedAt' => now(),
            'orgName' => $identity['display_name'],
            'identity' => $identity,
        ])->output();
    }

    public static function filename(string $key, Period $period, string $extension): string
    {
        $from = $period->start->format('Ymd');
        $to = $period->end->subDay()->format('Ymd');
        $range = $from === $to ? $from : "{$from}-{$to}";

        return Str::slug("informe-{$key}-{$range}").'.'.$extension;
    }

    /**
     * @return array<int, string>
     */
    private static function totalsRow(ReportTable $table, string $decimal): array
    {
        $cells = [];
        foreach ($table->columns as $index => $column) {
            if ($index === 0) {
                $cells[] = __('Total');

                continue;
            }
            $cells[] = array_key_exists($column->key, $table->totals)
                ? $column->export($table->totals[$column->key], $decimal)
                : '';
        }

        return $cells;
    }

    /**
     * @return array{0: string, 1: string} [delimiter, decimal separator]
     */
    private static function localeCsvFormat(): array
    {
        return app()->getLocale() === 'es' ? [';', ','] : [',', '.'];
    }
}
