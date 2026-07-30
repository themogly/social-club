<?php

namespace App\Filament\Widgets;

/**
 * Top genetics dispensed in the period as a horizontal bar, toggleable between grams
 * and contribution amount (the filter above the chart) — no many-slice pie.
 */
class DispensedByGeneticChart extends DashboardChart
{
    public function getHeading(): string
    {
        return __('Dispensado por genética');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, string>
     */
    protected function getFilters(): ?array
    {
        return [
            'grams' => __('Gramos'),
            'value' => __('Importe'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $rows = $this->charts()->dispensedByGenetic(10);

        if ($rows === []) {
            return [];
        }

        $byValue = $this->filter === 'value';
        $p = $this->palette();

        return [
            'labels' => array_map(static fn (array $r): string => $r['genetic'], $rows),
            'datasets' => [[
                'label' => $byValue ? __('Importe (€)') : __('Gramos'),
                'data' => $byValue
                    ? array_map(static fn (array $r): float => $r['total_cents'] / 100, $rows)
                    : array_map(static fn (array $r): float => $r['grams_cg'] / 100, $rows),
                'backgroundColor' => $p['brand_soft'],
                'borderColor' => $p['brand'],
                'borderWidth' => 1,
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return array_merge(parent::getOptions(), [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
        ]);
    }
}
