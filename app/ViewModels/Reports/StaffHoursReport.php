<?php

namespace App\ViewModels\Reports;

use App\Models\User;
use App\Support\Duration;
use App\Support\Period;
use App\ViewModels\StaffHours;

/**
 * "Horas del personal" as an Informes report (prompt 285) — a thin shell over the ONE reader, `StaffHours`, so the
 * page's table, its CSV/PDF and its charts are the same figures by construction.
 */
class StaffHoursReport extends AbstractReport
{
    /**
     * @param  list<string>  $locationIds  the sedes the viewer may see, narrowed to the page's scope
     */
    public function __construct(string $organisationId, array $locationIds, Period $period, private readonly User $viewer)
    {
        parent::__construct($organisationId, $locationIds, $period);
    }

    public function hours(): StaffHours
    {
        return StaffHours::for($this->viewer, $this->period, $this->locationIds ?? []);
    }

    public function key(): string
    {
        return 'staff_hours';
    }

    public function title(): string
    {
        return __('Horas del personal');
    }

    /** @return list<ReportTable> */
    protected function build(): array
    {
        return [$this->hours()->table()];
    }

    /** The headline figures as the PDF's summary strip (the page shows them as stat cards). */
    public function summary(): array
    {
        $s = $this->hours()->summary();

        return [
            ['label' => __('Horas'), 'value' => Duration::hours($s['total_minutes'])],
            ['label' => __('Personas'), 'value' => (string) $s['people']],
            ['label' => __('Jornada media'), 'value' => Duration::format($s['average_shift_minutes'])],
            ['label' => __('Declaradas o corregidas'), 'value' => $s['declared_percent'].' %'],
        ];
    }
}
