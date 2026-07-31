<?php

namespace App\Filament\Widgets;

use Illuminate\Support\Facades\Auth;

/**
 * Income vs expenses for the period, with the superávit (surplus) drawn as a line on
 * top of the two bars — the club's break-even at a glance.
 */
class IncomeVsExpensesChart extends DashboardChart
{
    /** Finance authorisation (audit A1): a STAFF user without reports.view* cannot mount this widget. */
    public static function canView(): bool
    {
        return Auth::user()?->canAny(['reports.view', 'reports.view.all']) ?? false;
    }

    public function getHeading(): string
    {
        return __('Ingresos vs gastos');
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
        $d = $this->charts()->incomeVsExpenses();

        if (array_sum($d['ingresos']) + array_sum($d['gastos']) === 0) {
            return [];
        }

        $p = $this->palette();

        return [
            'labels' => $d['labels'],
            'datasets' => [
                ['label' => __('Ingresos'), 'data' => $this->centsToEuros($d['ingresos']), 'backgroundColor' => $p['brand_soft'], 'borderColor' => $p['brand'], 'borderWidth' => 1, 'order' => 3],
                ['label' => __('Gastos'), 'data' => $this->centsToEuros($d['gastos']), 'backgroundColor' => $p['error_soft'], 'borderColor' => $p['error'], 'borderWidth' => 1, 'order' => 2],
                ['type' => 'line', 'label' => __('Superávit'), 'data' => $this->centsToEuros($d['superavit']), 'borderColor' => $p['success'], 'backgroundColor' => 'transparent', 'tension' => 0.3, 'pointRadius' => 3, 'order' => 1],
            ],
        ];
    }
}
