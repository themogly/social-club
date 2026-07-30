<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Reports\ReportPage;
use App\Support\Money;
use App\Support\Period;
use App\Support\Spreadsheet\AccountingExport;
use App\ViewModels\Reports\AbstractReport;
use App\ViewModels\Reports\FinancialReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportación contable — the period accounting export for the club's bookkeeper. A
 * period + sede picker, a totals preview, and a CSV download built by
 * App\Support\Spreadsheet\AccountingExport (income split by type, outgoings by category),
 * which is DERIVED from the same FinancialReport shown on screen so the figures reconcile
 * to the cent. Gated on `reports.export`; the sede scope is refused (403) beyond the
 * actor's own unless they hold reports.view.all.
 */
class ExportacionContable extends ReportPage
{
    protected string $view = 'filament.pages.exportacion-contable';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'documentos/exportacion-contable';

    public static function getNavigationLabel(): string
    {
        return __('Exportación contable');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Documentos');
    }

    public function getHeading(): string
    {
        return __('Exportación contable');
    }

    public function getSubheading(): ?string
    {
        return __('Datos para la contabilidad de la asociación. No constituye asesoramiento legal ni contable.');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->can('reports.export') ?? false;
    }

    protected function makeReport(string $organisationId, ?array $locationIds, Period $period): AbstractReport
    {
        return new FinancialReport($organisationId, $locationIds, $period);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $totals = $this->accountingExport()->totals();

        return [
            'income' => Money::fromCents($totals['income_cents'])->formatted(),
            'expenses' => Money::fromCents($totals['expenses_cents'])->formatted(),
            'surplus' => Money::fromCents($totals['surplus_cents'])->formatted(),
            'periodKey' => $this->period,
            'customStart' => $this->customStart,
            'customEnd' => $this->customEnd,
            'scope' => $this->scope ?? $this->defaultScope(),
            'scopeOptions' => $this->scopeOptions(),
            'showScope' => count($this->scopeOptions()) > 1,
            'scopeLabel' => $this->scopeLabel(),
            'periodLabel' => $this->periodLabel(),
        ];
    }

    public function exportCsv(): StreamedResponse
    {
        $content = $this->accountingExport()->csv();
        $period = $this->resolvePeriod();
        $from = $period->start->format('Ymd');
        $to = $period->end->subDay()->format('Ymd');
        $range = $from === $to ? $from : "{$from}-{$to}";
        $filename = "exportacion-contable-{$range}.csv";

        return response()->streamDownload(fn () => print ($content), $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    protected function accountingExport(): AccountingExport
    {
        return AccountingExport::forPeriod($this->organisationId(), $this->resolveLocationIds(), $this->resolvePeriod());
    }
}
