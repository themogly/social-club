<?php

namespace App\Filament\Widgets;

/**
 * On-hand grams by genetic against the premises stock ceiling (the dashed reference
 * line) — a compliance signal: the club should be able to see it is not holding more
 * than `active members × daily limit × ceiling days` on site.
 */
class StockLevelsChart extends DashboardChart
{
    public function getHeading(): string
    {
        return __('Niveles de stock');
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
        $d = $this->charts()->stockLevelsByGenetic(12);

        if ($d['labels'] === []) {
            return [];
        }

        $p = $this->palette();
        $ceiling = array_fill(0, count($d['labels']), $d['ceiling_grams']);

        return [
            'labels' => $d['labels'],
            'datasets' => [
                ['label' => __('En stock (g)'), 'data' => $d['grams'], 'backgroundColor' => $p['brand_soft'], 'borderColor' => $p['brand'], 'borderWidth' => 1, 'order' => 2],
                ['type' => 'line', 'label' => __('Techo de existencias'), 'data' => $ceiling, 'borderColor' => $p['error'], 'borderDash' => [6, 4], 'backgroundColor' => 'transparent', 'pointRadius' => 0, 'order' => 1],
            ],
        ];
    }
}
