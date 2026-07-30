{{--
    The club dashboard. One period control (below) drives every figure on the page.
    Layout: a wide main column + a right rail on ≥1024, a single column below. All
    styling is scoped under .csc-dash and theme-aware via Filament's .dark class, so
    the admin theme never leaks into the public/counter stylesheets.
--}}
<x-filament-panels::page>
    <div class="csc-dash" wire:key="csc-dash-{{ $periodKey }}">
        {{-- Period control — the single toggle every card, chart and table reads. --}}
        <div class="csc-toolbar" role="group" aria-label="{{ __('Período') }}">
            <div class="csc-segmented">
                @foreach (['today' => __('Hoy'), 'week' => __('Esta semana'), 'month' => __('Este mes'), 'custom' => __('Personalizado')] as $key => $label)
                    <button
                        type="button"
                        wire:click="$set('period', '{{ $key }}')"
                        @class(['csc-seg', 'csc-seg-active' => $periodKey === $key])
                        aria-pressed="{{ $periodKey === $key ? 'true' : 'false' }}"
                    >{{ $label }}</button>
                @endforeach
            </div>

            @if ($periodKey === 'custom')
                <div class="csc-dates">
                    <label class="csc-date">
                        <span>{{ __('Desde') }}</span>
                        <input type="date" wire:model.live="customStart" max="{{ now()->toDateString() }}">
                    </label>
                    <label class="csc-date">
                        <span>{{ __('Hasta') }}</span>
                        <input type="date" wire:model.live="customEnd" max="{{ now()->toDateString() }}">
                    </label>
                </div>
            @endif

            @if ($isRollup)
                <span class="csc-scope-pill">{{ __('Todas las sedes') }}</span>
            @endif
        </div>

        <div class="csc-grid">
            {{-- ============================ MAIN COLUMN ============================ --}}
            <div class="csc-main">
                {{-- Stat cards — one reusable component, parameterised. --}}
                <div class="csc-cards">
                    @foreach ($stats as $card)
                        <x-dashboard.stat-card :card="$card" />
                    @endforeach
                </div>

                @if ($role !== 'staff')
                    {{-- Charts. Each is a Filament ChartWidget re-keyed to the period so
                         the one control re-drives it; empty periods show a designed state. --}}
                    @if ($canSeeFinance)
                        <div class="csc-chart-grid">
                            <x-dashboard.section :title="__('Ingresos por período')">
                                @livewire(\App\Filament\Widgets\IncomeByPeriodChart::class, ['periodKey' => $periodKey, 'customStart' => $customStart, 'customEnd' => $customEnd], 'income-'.$periodKey.'-'.$customStart.'-'.$customEnd)
                            </x-dashboard.section>
                            <x-dashboard.section :title="__('Ingresos vs gastos')">
                                @livewire(\App\Filament\Widgets\IncomeVsExpensesChart::class, ['periodKey' => $periodKey, 'customStart' => $customStart, 'customEnd' => $customEnd], 'invsex-'.$periodKey.'-'.$customStart.'-'.$customEnd)
                            </x-dashboard.section>
                        </div>
                    @endif

                    <div class="csc-chart-grid">
                        <x-dashboard.section :title="__('Dispensado por genética')">
                            @livewire(\App\Filament\Widgets\DispensedByGeneticChart::class, ['periodKey' => $periodKey, 'customStart' => $customStart, 'customEnd' => $customEnd], 'bygenetic-'.$periodKey.'-'.$customStart.'-'.$customEnd)
                        </x-dashboard.section>
                        <x-dashboard.section :title="__('Distribución de consumo')">
                            @livewire(\App\Filament\Widgets\ConsumptionDistributionChart::class, ['periodKey' => $periodKey, 'customStart' => $customStart, 'customEnd' => $customEnd], 'distribution-'.$periodKey.'-'.$customStart.'-'.$customEnd)
                        </x-dashboard.section>
                    </div>

                    <x-dashboard.section :title="__('Niveles de stock')">
                        @livewire(\App\Filament\Widgets\StockLevelsChart::class, ['periodKey' => $periodKey, 'customStart' => $customStart, 'customEnd' => $customEnd], 'stock-'.$periodKey.'-'.$customStart.'-'.$customEnd)
                    </x-dashboard.section>
                @endif

                {{-- Tables — one reusable table component for both. --}}
                <div class="csc-two">
                    <x-dashboard.section :title="__('Top dispensado')">
                        <x-dashboard.data-table
                            :headers="$canSeeFinance ? [__('Genética'), __('Tx'), __('Gramos'), __('Total')] : [__('Genética'), __('Tx'), __('Gramos')]"
                            :numeric="[1, 2, 3]"
                            :rows="$topDispensedRows"
                            :empty="__('Nada dispensado en este período')"
                        />
                    </x-dashboard.section>

                    <x-dashboard.section :title="__('Últimas transacciones')">
                        <x-dashboard.data-table
                            :headers="$canSeeFinance ? [__('Tipo'), __('Socio'), __('Operador'), __('Importe')] : [__('Tipo'), __('Socio'), __('Operador')]"
                            :numeric="[3]"
                            :rows="$recentRows"
                            :empty="__('Sin transacciones en este período')"
                        />
                    </x-dashboard.section>
                </div>

                {{-- Footfall heatmap (hour × weekday) — a CSS grid, legible in both themes. --}}
                <x-dashboard.section :title="__('Afluencia por hora y día')" :subtitle="__('Entradas registradas en el período')">
                    <x-dashboard.heatmap :footfall="$footfall" />
                </x-dashboard.section>

                {{-- Owner-only per-location comparison (org rollup). --}}
                @if ($isRollup && count($comparisonRows) > 1)
                    <x-dashboard.section :title="__('Comparativa por sede')">
                        <x-dashboard.data-table
                            :headers="[__('Sede'), __('Aportaciones'), __('Dispensado'), __('Dentro')]"
                            :numeric="[1, 2, 3]"
                            :rows="$comparisonRows"
                            :empty="__('Sin sedes')"
                        />
                    </x-dashboard.section>
                @endif
            </div>

            {{-- ============================ RIGHT RAIL ============================ --}}
            <aside class="csc-rail">
                <x-dashboard.alerts :alerts="$alerts" />

                @foreach ($readouts as $group)
                    <x-dashboard.section :title="$group['title']">
                        <dl class="csc-readout">
                            @foreach ($group['rows'] as $row)
                                <div class="csc-readout-row">
                                    <dt>{{ $row['label'] }}</dt>
                                    <dd>
                                        @if (($row['href'] ?? '#') !== '#')
                                            <a href="{{ $row['href'] }}">{{ $row['value'] }}</a>
                                        @else
                                            {{ $row['value'] }}
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    </x-dashboard.section>
                @endforeach
            </aside>
        </div>
    </div>

    @include('filament.pages.partials.dashboard-styles')
</x-filament-panels::page>
