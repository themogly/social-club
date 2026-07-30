<?php

namespace App\Filament\Pages\Reports;

use App\Support\Period;
use App\ViewModels\Reports\AbstractReport;
use App\ViewModels\Reports\FinancialReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class FinancialReportPage extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyEuro;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'informes/financiero';

    public static function getNavigationLabel(): string
    {
        return __('Financiero');
    }

    protected function makeReport(string $organisationId, ?array $locationIds, Period $period): AbstractReport
    {
        return new FinancialReport($organisationId, $locationIds, $period);
    }
}
