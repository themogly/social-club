<?php

namespace App\Filament\Pages\Reports;

use App\Support\Period;
use App\ViewModels\Reports\AbstractReport;
use App\ViewModels\Reports\StockReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class StockReportPage extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'informes/stock';

    public static function getNavigationLabel(): string
    {
        return __('Existencias');
    }

    protected function makeReport(string $organisationId, ?array $locationIds, Period $period): AbstractReport
    {
        return new StockReport($organisationId, $locationIds, $period);
    }
}
