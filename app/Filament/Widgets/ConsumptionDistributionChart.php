<?php

namespace App\Filament\Widgets;

/**
 * How the active membership sits against its monthly limit — a histogram of members by
 * the share of their cap used this month. The bands are coloured by risk (green low,
 * red over-limit) so the compliance shape reads instantly.
 */
class ConsumptionDistributionChart extends DashboardChart
{
    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $d = $this->charts()->consumptionDistribution();

        if (array_sum($d['counts']) === 0) {
            return [];
        }

        $p = $this->palette();

        return [
            'labels' => $d['labels'],
            'datasets' => [[
                'label' => __('Socios'),
                'data' => $d['counts'],
                'backgroundColor' => [$p['success_soft'], $p['success_soft'], $p['brand_soft'], $p['warning_soft'], $p['error_soft']],
                'borderColor' => [$p['success'], $p['success'], $p['brand'], $p['warning'], $p['error']],
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
            'plugins' => ['legend' => ['display' => false]],
        ]);
    }
}
