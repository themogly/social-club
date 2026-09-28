<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Support\Duration;
use App\ViewModels\StaffHours;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

/**
 * "Horas del personal" on the dashboard (prompt 285) — one horizontal bar per person for the selected period, split
 * into clocked minutes and declared/corrected minutes (the lighter part), total in the label, longest first. Not split
 * by sede even in the rollup: that detail is on the report page. Read through `StaffHours` like every hours figure.
 */
class StaffHoursChart extends DashboardChart
{
    protected ?string $maxHeight = '18rem';

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User && StaffHours::visibleOnDashboard($user);
    }

    public function getEmptyStateHeading(): string|Htmlable
    {
        return __('Nadie ha fichado en este período.');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        /** @var User $user */
        $user = Auth::user();
        $people = array_values(array_filter(StaffHours::for($user, $this->period())->perPerson(), fn (array $r): bool => $r['total_minutes'] > 0));

        if ($people === []) {
            return [];
        }

        $p = $this->palette();
        $hours = fn (int $minutes): float => round($minutes / 60, 2);

        return [
            'labels' => array_map(fn (array $r): string => ($r['left'] ? __(':name (ya no está)', ['name' => $r['name']]) : $r['name']).' · '.Duration::hours($r['total_minutes']), $people),
            'datasets' => [
                ['label' => __('Fichadas'), 'data' => array_map(fn (array $r): float => $hours($r['clocked_minutes']), $people), 'backgroundColor' => $p['brand_soft'], 'borderColor' => $p['brand'], 'borderWidth' => 1],
                ['label' => __('Declaradas o corregidas'), 'data' => array_map(fn (array $r): float => $hours($r['declared_minutes']), $people), 'backgroundColor' => $p['brand_faint'], 'borderColor' => $p['brand'], 'borderWidth' => 1],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'maintainAspectRatio' => false,
            'plugins' => ['legend' => ['display' => true, 'position' => 'bottom']],
            'scales' => [
                'x' => ['stacked' => true, 'beginAtZero' => true, 'title' => ['display' => true, 'text' => __('Horas')]],
                'y' => ['stacked' => true, 'grid' => ['display' => false]],
            ],
        ];
    }
}
