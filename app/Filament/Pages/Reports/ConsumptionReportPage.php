<?php

namespace App\Filament\Pages\Reports;

use App\Support\Period;
use App\ViewModels\Reports\AbstractReport;
use App\ViewModels\Reports\ConsumptionReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class ConsumptionReportPage extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'informes/consumo';

    public static function getNavigationLabel(): string
    {
        return __('Consumo');
    }

    protected function makeReport(string $organisationId, ?array $locationIds, Period $period): AbstractReport
    {
        return new ConsumptionReport($organisationId, $locationIds, $period);
    }
}
