<?php

namespace App\Filament\Pages\Reports;

use App\Support\Period;
use App\ViewModels\Reports\AbstractReport;
use App\ViewModels\Reports\MembersReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class MembersReportPage extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'informes/socios';

    public static function getNavigationLabel(): string
    {
        return __('Socios');
    }

    protected function makeReport(string $organisationId, ?array $locationIds, Period $period): AbstractReport
    {
        return new MembersReport($organisationId, $locationIds, $period);
    }
}
