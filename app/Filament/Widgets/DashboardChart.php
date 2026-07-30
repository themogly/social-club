<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Support\Period;
use App\ViewModels\DashboardCharts;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

/**
 * The ONE chart wrapper. Every dashboard chart extends this: it receives the page's
 * period (so the single period control drives every chart), resolves the same scoped
 * `DashboardCharts` view-model the rest of the page uses, carries the shared brand
 * palette + options, and shows a designed empty state (never a blank canvas) when the
 * selected period has no data. Concrete charts only declare their type + data.
 */
abstract class DashboardChart extends ChartWidget
{
    /** The page passes its period down; a changing value re-keys and re-mounts the chart. */
    public string $periodKey = 'today';

    public ?string $customStart = null;

    public ?string $customEnd = null;

    protected ?string $maxHeight = '16rem';

    public function mount(?string $periodKey = null, ?string $customStart = null, ?string $customEnd = null): void
    {
        $this->periodKey = $periodKey ?? $this->periodKey;
        $this->customStart = $customStart;
        $this->customEnd = $customEnd;

        parent::mount();
    }

    public function getEmptyStateHeading(): string|Htmlable
    {
        return __('Sin datos en este período');
    }

    public function getEmptyStateDescription(): string|Htmlable|null
    {
        return __('Cuando haya actividad en el período seleccionado, aparecerá aquí.');
    }

    protected function period(): Period
    {
        if ($this->periodKey === 'custom' && filled($this->customStart) && filled($this->customEnd)) {
            return Period::custom(
                CarbonImmutable::parse($this->customStart),
                CarbonImmutable::parse($this->customEnd),
            );
        }

        return Period::fromKey($this->periodKey);
    }

    protected function charts(): DashboardCharts
    {
        /** @var User $user */
        $user = Auth::user();

        return DashboardCharts::for($user, $this->period());
    }

    /**
     * Brand + semantic palette (the CLAUDE tokens). Hex is theme-independent, so the
     * data marks read in both light and dark; Filament themes the axes/grid itself.
     *
     * @return array<string, string>
     */
    protected function palette(): array
    {
        return [
            'brand' => '#2563eb',
            'brand_soft' => 'rgba(37, 99, 235, 0.65)',
            'success' => '#16a34a',
            'success_soft' => 'rgba(22, 163, 74, 0.65)',
            'warning' => '#d97706',
            'warning_soft' => 'rgba(217, 119, 6, 0.65)',
            'error' => '#dc2626',
            'error_soft' => 'rgba(220, 38, 38, 0.6)',
            'muted' => 'rgba(100, 116, 139, 0.55)',
        ];
    }

    /**
     * @param  list<int>  $cents
     * @return list<float>
     */
    protected function centsToEuros(array $cents): array
    {
        return array_map(static fn (int $c): float => $c / 100, $cents);
    }

    /**
     * @param  list<int>  $centigrams
     * @return list<float>
     */
    protected function cgToGrams(array $centigrams): array
    {
        return array_map(static fn (int $c): float => $c / 100, $centigrams);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => ['display' => true, 'position' => 'bottom'],
            ],
            'scales' => [
                'x' => ['grid' => ['display' => false]],
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
        ];
    }
}
