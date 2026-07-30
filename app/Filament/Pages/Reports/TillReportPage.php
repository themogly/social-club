<?php

namespace App\Filament\Pages\Reports;

use App\Support\Period;
use App\ViewModels\Reports\AbstractReport;
use App\ViewModels\Reports\TillReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class TillReportPage extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'informes/cajas';

    public static function getNavigationLabel(): string
    {
        return __('Cajas');
    }

    protected function makeReport(string $organisationId, ?array $locationIds, Period $period): AbstractReport
    {
        return new TillReport($organisationId, $locationIds, $period);
    }
}
