<?php

namespace App\Filament\Pages\Reports;

use App\Support\Period;
use App\ViewModels\Reports\AbstractReport;
use App\ViewModels\Reports\DebtorReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/**
 * Deudores — who owes the club money (prompt 82). Location-scoped like the other operational reports;
 * gated on reports.view (the org-wide "All" rollup additionally needs reports.view.all, via ReportPage).
 */
class DebtorReportPage extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 65;

    protected static ?string $slug = 'informes/deudores';

    public static function getNavigationLabel(): string
    {
        return __('Deudores');
    }

    protected function makeReport(string $organisationId, ?array $locationIds, Period $period): AbstractReport
    {
        return new DebtorReport($organisationId, $locationIds, $period);
    }
}
