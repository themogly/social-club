<?php

namespace App\Filament\Pages\Reports;

use App\Support\Period;
use App\ViewModels\Reports\AbstractReport;
use App\ViewModels\Reports\TillReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

class TillReportPage extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'informes/cajas';

    /** Prompt 366 — «Solo con diferencia», in the URL so the dashboard line and the morning summary land on it. */
    #[Url]
    public bool $diferencia = false;

    /** Prompt 380 — «Botes sin contar»: the closes that left a «Cada noche» box uncounted; in the URL for the dashboard line. */
    #[Url]
    public bool $sin_contar = false;

    public function mount(): void
    {
        parent::mount();

        // The dashboard line's link: ?period=week — the same «Esta semana» window it counted.
        if (in_array(request()->query('period'), ['today', 'week', 'month'], true)) {
            $this->period = (string) request()->query('period');
        }
    }

    public static function getNavigationLabel(): string
    {
        return __('Cajas');
    }

    protected function makeReport(string $organisationId, ?array $locationIds, Period $period): AbstractReport
    {
        return (new TillReport($organisationId, $locationIds, $period))->onlyWithVariance($this->diferencia)->onlyUncountedBoxes($this->sin_contar);
    }

    protected function getViewData(): array
    {
        return parent::getViewData() + ['controlsView' => 'filament.pages.reports.partials.till-controls'];
    }
}
