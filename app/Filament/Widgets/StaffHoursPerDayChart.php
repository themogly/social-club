<?php

namespace App\Filament\Widgets;

use Illuminate\Contracts\Support\Htmlable;

/** Hours per business day (per week past 62 days), stacked by person — gaps and heavy days (prompt 285). */
class StaffHoursPerDayChart extends StaffHoursReportChart
{
    protected ?string $maxHeight = '18rem';

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
        $days = $hours->perDay();

        if ($days['series'] === [] || array_sum(array_map('array_sum', $days['series'])) === 0) {
            return [];
        }

        $colours = $this->seriesColours();
        $datasets = [];
        $i = 0;
        foreach ($days['series'] as $userId => $minutes) {
            [$fill, $border] = $colours[$i++ % count($colours)];
            $datasets[] = [
                'label' => $hours->label((string) $userId),
                'data' => array_map(fn (int $m): float => $this->toHours($m), $minutes),
                'backgroundColor' => $fill, 'borderColor' => $border, 'borderWidth' => 1,
            ];
        }

        return ['labels' => $days['labels'], 'datasets' => $datasets];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'maintainAspectRatio' => false,
            'plugins' => ['legend' => ['display' => true, 'position' => 'bottom']],
            'scales' => [
                'x' => ['stacked' => true, 'grid' => ['display' => false]],
                'y' => ['stacked' => true, 'beginAtZero' => true, 'title' => ['display' => true, 'text' => __('Horas')]],
            ],
        ];
    }
}
