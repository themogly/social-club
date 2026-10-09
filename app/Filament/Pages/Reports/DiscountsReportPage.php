<?php

namespace App\Filament\Pages\Reports;

use App\Support\Period;
use App\ViewModels\Reports\AbstractReport;
use App\ViewModels\Reports\DiscountsReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * Prompt 291 — Informes → Descuentos y ajustes: everything given away, by whom. `reports.view` (the ReportPage default):
 * a manager sees their sedes, the owner the rollup, STAFF never. An operator's name opens the detail filtered to them.
 * (Its dashboard alert became prompt 367's losses alert, which opens Informes → Pérdidas.)
 */
class DiscountsReportPage extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 25;

    protected static ?string $slug = 'informes/descuentos';

    /** The detail's filters and page ride in the URL, so the alert and the names can link straight to them. */
    #[Url]
    public ?string $kind = null;

    #[Url]
    public ?string $operator = null;

    #[Url]
    public int $detailPage = 1;

    public static function getNavigationLabel(): string
    {
        return __('Descuentos y ajustes');
    }

    public function updatedKind(): void
    {
        $this->detailPage = 1;
    }

    public function updatedOperator(): void
    {
        $this->detailPage = 1;
    }

    public function goToDetailPage(int $page): void
    {
        $this->detailPage = max(1, $page);
    }

    protected function makeReport(string $organisationId, ?array $locationIds, Period $period): AbstractReport
    {
        return (new DiscountsReport($organisationId, $locationIds, $period))->withDetail($this->kind, $this->operator, $this->detailPage);
    }

    protected function getViewData(): array
    {
        /** @var DiscountsReport $report */
        $report = $this->report();

        return parent::getViewData() + [
            'controlsView' => 'filament.pages.reports.partials.discounts-controls',
            'discountOperators' => $report->operatorOptions(),
            'discountPages' => $report->detailPages(),
        ];
    }
}
