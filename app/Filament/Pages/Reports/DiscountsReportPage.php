<?php

namespace App\Filament\Pages\Reports;

use App\Support\Period;
use App\ViewModels\Reports\AbstractReport;
use App\ViewModels\Reports\DiscountsReport;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * Prompt 291 — Informes → Descuentos y ajustes: everything given away, by whom. `reports.view` (the ReportPage default):
 * a manager sees their sedes, the owner the rollup, STAFF never. The dashboard alert lands here on the last 7 days,
 * sorted by discretionary %; an operator's name opens the detail filtered to them.
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

    public function mount(): void
    {
        parent::mount();

        // The dashboard alert's link: ?days=7 → the last 7 days, sorted by discretionary %.
        if ((int) request()->query('days') === DiscountsReport::ALERT_DAYS) {
            $this->period = 'custom';
            $this->customStart = CarbonImmutable::now()->subDays(DiscountsReport::ALERT_DAYS)->toDateString();
            $this->customEnd = CarbonImmutable::now()->toDateString();
            $this->sort = 'discrecional_pct';
            $this->sortDir = 'desc';
        }
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
