<?php

namespace App\Filament\Widgets;

use Illuminate\Support\Facades\Auth;

/**
 * Income across the period, kept as three deliberately-separate streams — Aportaciones
 * (cannabis), Barra (bar/merch) and Cuotas (membership fees). They are never merged
 * into one "revenue" figure, so the non-profit accounting stays legible.
 */
class IncomeByPeriodChart extends DashboardChart
{
    /** Finance authorisation (audit A1): a STAFF user without reports.view* cannot mount this widget. */
    public static function canView(): bool
    {
        return Auth::user()?->canAny(['reports.view', 'reports.view.all']) ?? false;
    }

    public function getHeading(): string
    {
        return __('Ingresos por período');
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
        $d = $this->charts()->incomeByPeriod();

        if (array_sum($d['aportaciones']) + array_sum($d['barra']) + array_sum($d['cuotas']) === 0) {
            return [];
        }

        $p = $this->palette();

        return [
            'labels' => $d['labels'],
            'datasets' => [
                ['label' => __('Aportaciones'), 'data' => $this->centsToEuros($d['aportaciones']), 'backgroundColor' => $p['brand_soft'], 'borderColor' => $p['brand'], 'borderWidth' => 1],
                ['label' => __('Barra y tienda'), 'data' => $this->centsToEuros($d['barra']), 'backgroundColor' => $p['success_soft'], 'borderColor' => $p['success'], 'borderWidth' => 1],
                ['label' => __('Cuotas'), 'data' => $this->centsToEuros($d['cuotas']), 'backgroundColor' => $p['warning_soft'], 'borderColor' => $p['warning'], 'borderWidth' => 1],
            ],
        ];
    }
}
