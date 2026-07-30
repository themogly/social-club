{{--
    Exportación contable — period + sede picker, a reconciling totals preview and a CSV
    download (App\Support\Spreadsheet\AccountingExport). Reuses the report .csc-dash tokens.
--}}
<x-filament-panels::page>
    <div class="csc-dash csc-report" wire:key="contable-{{ $periodKey }}-{{ $scope }}">
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
                <x-filament::button wire:click="exportCsv" wire:target="exportCsv" size="sm" color="primary"
                    icon="heroicon-o-arrow-down-tray">
                    {{ __('Descargar CSV') }}
                </x-filament::button>
            </div>
        </div>

        <p class="csc-rep-context">{{ $scopeLabel }} · {{ $periodLabel }}</p>

        <div class="csc-summary">
            <div class="csc-chip csc-chip-success">
                <span class="csc-chip-label">{{ __('Ingresos') }}</span>
                <span class="csc-chip-value">{{ $income }}</span>
            </div>
            <div class="csc-chip csc-chip-warning">
                <span class="csc-chip-label">{{ __('Gastos') }}</span>
                <span class="csc-chip-value">{{ $expenses }}</span>
            </div>
            <div class="csc-chip csc-chip-default">
                <span class="csc-chip-label">{{ __('Superávit') }}</span>
                <span class="csc-chip-value">{{ $surplus }}</span>
            </div>
        </div>

        <x-dashboard.section :title="__('Exportación contable')">
            <p class="csc-rep-note">
                {{ __('El CSV reproduce estas cifras (ingresos por tipo, gastos por categoría y superávit), conciliadas con el informe financiero. Vocabulario de la asociación: aportaciones, cuotas, superávit — nunca venta ni beneficio.') }}
            </p>
        </x-dashboard.section>
    </div>

    @include('filament.pages.partials.dashboard-styles')
    @include('filament.pages.partials.report-styles')
</x-filament-panels::page>
