<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\ViewModels\StaffHours;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

/**
 * The "Horas del personal" report's charts (prompt 285): the page's period AND its sede scope, read through the same
 * `StaffHours` as the table. The sede list arrives from the server at mount and is #[Locked]; `StaffHours::for()`
 * intersects it with what the viewer may see anyway, so a tampered list can only ever narrow.
 */
abstract class StaffHoursReportChart extends DashboardChart
{
    /** @var list<string> */
    #[Locked]
    public array $locationIds = [];

    /**
     * @param  list<string>  $locationIds
     */
    public function mount(?string $periodKey = null, ?string $customStart = null, ?string $customEnd = null, array $locationIds = []): void
    {
        $this->locationIds = $locationIds;

        parent::mount($periodKey, $customStart, $customEnd);
    }

    public static function canView(): bool
    {
        return Auth::user()?->can('staff.hours.view') ?? false;
    }

    protected function hours(): StaffHours
    {
        /** @var User $user */
        $user = Auth::user();

        return StaffHours::for($user, $this->period(), $this->locationIds);
    }

    /** Minutes → hours for the chart axis (a display value; the figures stay integer minutes everywhere else). */
    protected function toHours(int $minutes): float
    {
        return round($minutes / 60, 2);
    }

    /**
     * The shared palette cycled for series (sedes, people) — the house colours only.
     *
     * @return list<array{0: string, 1: string}> [fill, border]
     */
    protected function seriesColours(): array
    {
        $p = $this->palette();

        return [
            [$p['brand_soft'], $p['brand']],
            [$p['success_soft'], $p['success']],
            [$p['warning_soft'], $p['warning']],
            [$p['error_soft'], $p['error']],
            [$p['brand_faint'], $p['brand']],
            [$p['muted'], $p['muted']],
        ];
    }
}
