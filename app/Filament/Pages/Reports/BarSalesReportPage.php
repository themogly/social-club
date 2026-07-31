<?php

namespace App\Filament\Pages\Reports;

use App\Support\Period;
use App\ViewModels\Reports\AbstractReport;
use App\ViewModels\Reports\BarSalesReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class BarSalesReportPage extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?int $navigationSort = 15;

    protected static ?string $slug = 'informes/ventas-barra';

    public static function getNavigationLabel(): string
    {
        return __('Barra y tienda');
    }

    protected function makeReport(string $organisationId, ?array $locationIds, Period $period): AbstractReport
    {
        return new BarSalesReport($organisationId, $locationIds, $period);
    }
}
