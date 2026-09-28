<?php

namespace App\Filament\Widgets;

use App\Support\Duration;
use Illuminate\Contracts\Support\Htmlable;

/** Hours per person, stacked by sede, longest first (prompt 285). One sede in scope: one colour, no legend. */
class StaffHoursBySedeChart extends StaffHoursReportChart
{
    protected ?string $maxHeight = '20rem';

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
        $hours = $this->hours();
        $people = array_values(array_filter($hours->perPerson(), fn (array $r): bool => $r['total_minutes'] > 0));

        if ($people === []) {
            return [];
        }

        $colours = $this->seriesColours();
        $datasets = [];
        foreach (array_keys($hours->locationNames()) as $i => $locationId) {
            $data = array_map(fn (array $r): float => $this->toHours($r['by_location'][$locationId] ?? 0), $people);
            if (array_sum($data) === 0.0) {
                continue;
            }
            [$fill, $border] = $colours[$i % count($colours)];
            $datasets[] = ['label' => $hours->locationNames()[$locationId], 'data' => $data, 'backgroundColor' => $fill, 'borderColor' => $border, 'borderWidth' => 1];
        }

        return [
            'labels' => array_map(fn (array $r): string => $hours->label($r['user_id']).' · '.Duration::hours($r['total_minutes']), $people),
            'datasets' => $datasets,
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
            'plugins' => ['legend' => ['display' => count($this->hours()->locationNames()) > 1, 'position' => 'bottom']],
            'scales' => [
                'x' => ['stacked' => true, 'beginAtZero' => true, 'title' => ['display' => true, 'text' => __('Horas')]],
                'y' => ['stacked' => true, 'grid' => ['display' => false]],
            ],
        ];
    }
}
