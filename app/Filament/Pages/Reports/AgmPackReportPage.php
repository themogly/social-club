<?php

namespace App\Filament\Pages\Reports;

use App\Support\Period;
use App\ViewModels\Reports\AbstractReport;
use App\ViewModels\Reports\AgmPackReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/**
 * The owner's assembly pack. Org-wide by construction ($orgWide) and gated on
 * reports.view.all, so a manager can neither open it nor export another club's figures.
 */
class AgmPackReportPage extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartBar;

    protected static ?int $navigationSort = 70;

    protected static ?string $slug = 'informes/comite';

    protected static bool $orgWide = true;

    public static function getNavigationLabel(): string
    {
        return __('Comité / Asamblea');
    }

    protected function makeReport(string $organisationId, ?array $locationIds, Period $period): AbstractReport
    {
        return new AgmPackReport($organisationId, null, $period);
    }
}
