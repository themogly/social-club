<?php

namespace App\Filament\Pages\Reports;

use App\Support\Period;
use App\ViewModels\Reports\AbstractReport;
use App\ViewModels\Reports\AttendanceReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class AttendanceReportPage extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'informes/asistencia';

    public static function getNavigationLabel(): string
    {
        return __('Asistencia');
    }

    protected function makeReport(string $organisationId, ?array $locationIds, Period $period): AbstractReport
    {
        return new AttendanceReport($organisationId, $locationIds, $period);
    }
}
