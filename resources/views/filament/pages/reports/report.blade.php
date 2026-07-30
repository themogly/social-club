{{--
    The shared Informes page. One period control + one location scope drive every table;
    the primary table sorts and exports. Styling reuses the dashboard's .csc-dash scope
    (theme-aware via Filament's .dark class) so nothing leaks into the public/counter CSS.
--}}
<x-filament-panels::page>
    <div class="csc-dash csc-report" wire:key="report-{{ $periodKey }}-{{ $scope }}">
        {{-- Toolbar: period · scope · exports --}}
        <div class="csc-toolbar">
            <div class="csc-segmented" role="group" aria-label="{{ __('Período') }}">
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

            @if ($showScope)
                <label class="csc-date csc-scope">
                    <span>{{ __('Sede') }}</span>
                    <select wire:model.live="scope" class="csc-select">
                        @foreach ($scopeOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            <div class="csc-exports">
                <x-filament::button wire:click="exportCsv" wire:target="exportCsv" size="sm" color="gray" outlined
                    icon="heroicon-o-table-cells">
                    {{ __('CSV') }}
                </x-filament::button>
                <x-filament::button wire:click="exportPdf" wire:target="exportPdf" size="sm" color="gray" outlined
                    icon="heroicon-o-document-arrow-down">
                    {{ __('PDF') }}
                </x-filament::button>
            </div>
        </div>

        {{-- Scope + period context, always visible so an empty report still reads as "here, now". --}}
        <p class="csc-rep-context">{{ $scopeLabel }} · {{ $periodLabel }}</p>

        @if ($isEmpty)
            {{-- Designed empty state — what to do, never a blank page. --}}
            <div class="csc-section">
                <div class="csc-empty csc-empty-hero">
                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedDocumentChartBar" class="csc-empty-ico" />
                    <p class="csc-empty-msg">{{ $tables[0]->empty ?? __('Sin datos en este período') }}</p>
                    @if ($tables[0]->emptyHint)
                        <p class="csc-empty-hint">{{ $tables[0]->emptyHint }}</p>
                    @endif
                    <p class="csc-empty-hint">{{ __('Prueba a ampliar el período o a cambiar de sede.') }}</p>
                </div>
            </div>
        @else
            {{-- Summary strip --}}
            @if (! empty($summary))
                <div class="csc-summary">
                    @foreach ($summary as $chip)
                        <div class="csc-chip csc-chip-{{ $chip['tone'] ?? 'default' }}">
                            <span class="csc-chip-label">{{ $chip['label'] }}</span>
                            <span class="csc-chip-value">{{ $chip['value'] }}</span>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Tables — the primary is sortable + exportable; the rest are supporting breakdowns. --}}
            @foreach ($tables as $i => $table)
                <x-dashboard.section :title="$table->title">
                    <x-reports.table
                        :table="$table"
                        :sortKey="$i === 0 ? $sortKey : null"
                        :sortDir="$sortDir"
                        :interactive="$i === 0"
                    />
                </x-dashboard.section>
            @endforeach
        @endif
    </div>

    @include('filament.pages.partials.dashboard-styles')
    @include('filament.pages.partials.report-styles')
</x-filament-panels::page>
