<?php

namespace App\Support\Spreadsheet;

use App\Support\Period;
use App\ViewModels\Reports\FinancialReport;
use App\ViewModels\Reports\ReportTable;
use League\Csv\Writer;

/**
 * The period accounting export for the club's bookkeeper — a CSV with a stable
 * column contract, income split by type and outgoings by category. It is DERIVED
 * from the same FinancialReport the club sees on screen (never re-computed), so the
 * export totals reconcile with the financial report to the cent by construction —
 * the *libros contables* obligation is met by the accountant, not reinvented in-app.
 */
class AccountingExport
{
    private const BOM = "\xEF\xBB\xBF";

    public function __construct(private FinancialReport $report) {}

    /**
     * @param  list<string>|null  $locationIds
     */
    public static function forPeriod(string $organisationId, ?array $locationIds, Period $period): self
    {
        return new self(new FinancialReport($organisationId, $locationIds, $period));
    }

    /**
     * @return array{income_cents: int, expenses_cents: int, surplus_cents: int}
     */
    public function totals(): array
    {
        $income = (int) ($this->table('methods')->totals['importe'] ?? 0);
        $expenses = (int) ($this->table('expenses')->totals['importe'] ?? 0);

        return [
            'income_cents' => $income,
            'expenses_cents' => $expenses,
            'surplus_cents' => $income - $expenses,
        ];
    }

    public function csv(): string
    {
        $decimal = app()->getLocale() === 'es' ? ',' : '.';
        $delimiter = app()->getLocale() === 'es' ? ';' : ',';
        $euros = fn (int $cents): string => number_format($cents / 100, 2, $decimal, '');

        $writer = Writer::createFromString();
        $writer->setDelimiter($delimiter);
        $writer->insertOne([__('Sección'), __('Concepto'), __('Importe')]);

        // Income by type (Aportaciones / Barra / Cuotas), from the takings table totals.
        $takings = $this->table('takings');
        foreach (['aportaciones', 'barra', 'cuotas'] as $key) {
            $column = $takings->column($key);
            if ($column !== null) {
                $writer->insertOne([__('Ingresos'), $column->label, $euros((int) ($takings->totals[$key] ?? 0))]);
            }
        }

        // Outgoings by category, from the expenses table rows.
        $expenses = $this->table('expenses');
        foreach ($expenses->rows as $row) {
            $writer->insertOne([__('Gastos'), (string) ($row['categoria'] ?? $row['concepto'] ?? '—'), $euros((int) ($row['importe'] ?? 0))]);
        }

        $t = $this->totals();
        $writer->insertOne([__('Totales'), __('Ingresos'), $euros($t['income_cents'])]);
        $writer->insertOne([__('Totales'), __('Gastos'), $euros($t['expenses_cents'])]);
        $writer->insertOne([__('Totales'), __('Superávit'), $euros($t['surplus_cents'])]);

        return self::BOM.$writer->toString();
    }

    private function table(string $key): ReportTable
    {
        foreach ($this->report->tables() as $table) {
            if ($table->key === $key) {
                return $table;
            }
        }

        // A period with no rows still has the (empty) tables; guard defensively.
        return new ReportTable(key: $key, title: $key, columns: [], rows: [], totals: []);
    }
}
