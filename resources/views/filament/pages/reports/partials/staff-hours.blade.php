{{-- Prompt 285 — the "Horas del personal" report's cards and charts, above the shared per-person table. Every figure
     comes from the one StaffHours reader; each chart carries a visually hidden summary line as its text alternative. --}}
<div class="csc-cards" data-staff-cards>
    @foreach ($staffCards as $card)
        <x-dashboard.stat-card :card="$card" />
    @endforeach
</div>

<p class="sr-only">{{ $staffSummaryLine }}</p>

<div class="csc-chart-grid">
    <x-dashboard.section :title="__('Horas por persona y sede')">
        <p class="sr-only">{{ $staffSummaryLine }}</p>
        @livewire(\App\Filament\Widgets\StaffHoursBySedeChart::class, $staffChartProps, 'staff-bysede-'.$staffChartKey)
    </x-dashboard.section>

    <x-dashboard.section :title="__('Horas por día')" :subtitle="$perDayByWeek ? __('Agrupadas por semana: el período pasa de 62 días.') : null">
        <p class="sr-only">{{ $staffSummaryLine }}</p>
        @livewire(\App\Filament\Widgets\StaffHoursPerDayChart::class, $staffChartProps, 'staff-perday-'.$staffChartKey)
    </x-dashboard.section>
</div>

{{-- Coverage beside demand: were there enough people in when members came? Side by side on desktop, stacked below. --}}
<div class="csc-heat-pair">
    <x-dashboard.section :title="__('Cobertura por hora y día')" :subtitle="__('Horas de personal fichadas en el período')">
        <x-dashboard.heatmap :footfall="$coverage" unit="minutes"
            :label="__('Mapa de calor de horas de personal por día de la semana y franja horaria')"
            :empty="__('Nadie ha fichado en este período.')" />
    </x-dashboard.section>

    <x-dashboard.section :title="__('Afluencia por hora y día')" :subtitle="__('Entradas registradas en el período')">
        <x-dashboard.heatmap :footfall="$footfall" />
    </x-dashboard.section>
</div>
