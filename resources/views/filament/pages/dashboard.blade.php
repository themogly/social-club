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

                    {{-- Legal stock headroom (prompt 134): the number nobody else in this market shows — how much
                         the club can still bring on-site before the compliance ceiling, per sede, with the three
                         inputs so it is auditable and actionable. Same figure prompt 110 enforces at intake. --}}
                    @if (! empty($ceilingHeadroom))
                        @php $gr = fn (int $cg): string => \App\Support\Weight::fromCentigrams($cg)->formatted(); @endphp
                        <x-dashboard.section :title="__('Techo legal de existencias')">
                            {{-- Prompt 272 — columns by the space the SECTION has, not the viewport: at 1024 beside the
                                 sidebar and the rail, `sm:grid-cols-2` squeezed each card to one word per line. --}}
                            <div class="grid grid-cols-[repeat(auto-fit,minmax(14rem,1fr))] gap-3" data-ceiling-headroom>
                                @foreach ($ceilingHeadroom as $h)
                                    <div @class([
                                        'rounded-xl border p-4',
                                        // Semantic token, not raw red-* — see the design audit; prompt 98's
                                        // per-scheme AA work only reaches --color-error.
                                        'border-error/40 bg-error/10' => $h['exceeded'],
                                        'border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900' => ! $h['exceeded'],
                                    ])>
                                        <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $h['location'] }}</p>
                                        @if ($h['exceeded'])
                                            <p class="mt-1 text-sm font-semibold text-error" data-headroom-over>
                                                {{ __('Supera el techo en :g.', ['g' => $gr($h['over_cg'])]) }}
                                            </p>
                                        @else
                                            <p class="mt-1 text-sm text-gray-700 dark:text-gray-200" data-headroom-grams>
                                                {{ __('Puedes dar de alta :g más antes de superar el límite.', ['g' => $gr($h['headroom_cg'])]) }}
                                            </p>
                                        @endif
                                        {{-- The three inputs, so the figure is auditable and the owner sees what would move it. --}}
                                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                            {{ __(':n socios activos × :d × :days días = techo :ceiling', [
                                                'n' => $h['active_members'],
                                                'd' => $gr($h['daily_limit_cg']),
                                                'days' => $h['ceiling_days'],
                                                'ceiling' => $gr($h['ceiling_cg']),
                                            ]) }}
                                        </p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('En sede ahora: :g', ['g' => $gr($h['on_site_cg'])]) }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </x-dashboard.section>
                    @endif

                    {{-- Prompt 285 — hours per person for the selected period (clocked + declared/corrected), for holders of
                         staff.hours.view only. The deeper breakdowns live on the "Horas del personal" report. --}}
                    @if ($staffHours)
                        @php $staffPeople = array_filter($staffHours->perPerson(), fn (array $r): bool => $r['total_minutes'] > 0); @endphp
                        <div data-staff-hours-chart>
                            <x-dashboard.section :title="__('Horas del personal')" :href="\App\Filament\Pages\Reports\StaffHoursReportPage::getUrl()">
                                <p class="sr-only">{{ trans_choice(':count persona ha fichado :hours en este período.|:count personas han fichado :hours en este período.', count($staffPeople), ['count' => count($staffPeople), 'hours' => \App\Support\Duration::hours((int) array_sum(array_column($staffPeople, 'total_minutes')))]) }}</p>
                                @livewire(\App\Filament\Widgets\StaffHoursChart::class, ['periodKey' => $periodKey, 'customStart' => $customStart, 'customEnd' => $customEnd], 'staffhours-'.$periodKey.'-'.$customStart.'-'.$customEnd)
                            </x-dashboard.section>
                        </div>
                    @endif
                @endif

                {{-- Tables — one reusable table component for both. --}}
                <div class="csc-two">
                    <x-dashboard.section :title="__('Top dispensado')">
                        <x-dashboard.data-table
                            :headers="$canSeeFinance ? [__('Genética'), __('Tx'), __('Gramos'), __('Total')] : [__('Genética'), __('Tx'), __('Gramos')]"
                            :numeric="[1, 2, 3]"
                            :rows="$topDispensedRows"
                            :label="__('Top dispensado')"
                            :empty="__('Nada dispensado en este período')"
                        />
                    </x-dashboard.section>

                    <x-dashboard.section :title="__('Últimas transacciones')">
                        <x-dashboard.data-table
                            :headers="$canSeeFinance ? [__('Tipo'), __('Socio'), __('Operador'), __('Importe')] : [__('Tipo'), __('Socio'), __('Operador')]"
                            :numeric="[3]"
                            :rows="$recentRows"
                            :label="__('Últimas transacciones')"
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
                            :label="__('Comparativa por sede')"
                            :empty="__('Sin sedes')"
                        />
                    </x-dashboard.section>
                @endif
            </div>

            {{-- ============================ RIGHT RAIL ============================ --}}
            <aside class="csc-rail">
                <x-dashboard.alerts :alerts="$alerts" />

                {{-- Prompt 285 — who is clocked in right now: today's live state, whatever the period control says. --}}
                @if ($staffHours)
                    <div data-staff-now>
                        <x-dashboard.section :title="__('Personal ahora')" :icon="\Filament\Support\Icons\Heroicon::OutlinedClock" :count="count($staffNow) ?: null">
                            @if ($staffNow === [])
                                <x-dashboard.empty :message="__('Nadie ha fichado entrada.')" :icon="\Filament\Support\Icons\Heroicon::OutlinedClock" />
                            @else
                                <ul class="csc-staff-now">
                                    @foreach ($staffNow as $row)
                                        <li class="csc-staff-now-row" data-staff-now-row>
                                            <span class="csc-staff-now-who">
                                                <span class="csc-staff-now-name">{{ $row['left'] ? __(':name (ya no está)', ['name' => $row['name']]) : $row['name'] }}</span>
                                                @if ($isRollup)
                                                    <span class="csc-staff-now-sede">{{ $row['location'] }}</span>
                                                @endif
                                            </span>
                                            <span class="csc-staff-now-time">
                                                <span>{{ __('Desde :time', ['time' => $row['in_time']]) }}</span>
                                                <span class="csc-staff-now-dur">{{ \App\Support\Duration::format($row['minutes']) }}</span>
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            @if (\App\Filament\Pages\RegistroJornada::canAccess())
                                <a href="{{ \App\Filament\Pages\RegistroJornada::getUrl() }}" class="csc-section-link csc-staff-now-link">{{ __('Registro de jornada') }}</a>
                            @endif
                        </x-dashboard.section>
                    </div>
                @endif

                @foreach ($readouts as $group)
                    <x-dashboard.section :title="$group['title']">
                        <dl class="csc-readout">
                            @foreach ($group['rows'] as $row)
                                <div class="csc-readout-row">
                                    <dt>{{ $row['label'] }}</dt>
                                    <dd>
                                        @if (($row['href'] ?? '#') !== '#')
                                            <a href="{{ $row['href'] }}" data-touch-target>{{ $row['value'] }}</a>
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
