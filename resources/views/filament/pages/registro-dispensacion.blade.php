{{--
    Registro de dispensación — the per-line dispensing control sheet. Reuses the report
    .csc-dash tokens (theme-aware) and the ConsumptionReport summary chips.
--}}
<x-filament-panels::page>
    <div class="csc-dash csc-report" wire:key="registro-{{ $periodKey }}-{{ $scope }}">
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

        <p class="csc-rep-context">{{ $scopeLabel }} · {{ $periodLabel }} · {{ trans_choice(':count dispensación|:count dispensaciones', $count, ['count' => $count]) }}</p>

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

        @if ($count === 0)
            <div class="csc-section">
                <div class="csc-empty csc-empty-hero">
                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedClipboardDocumentCheck" class="csc-empty-ico" />
                    <p class="csc-empty-msg">{{ __('Sin dispensaciones en este período') }}</p>
                    <p class="csc-empty-hint">{{ __('Cada dispensación registrada en el mostrador aparece aquí, línea a línea. Prueba a ampliar el período o a cambiar de sede.') }}</p>
                </div>
            </div>
        @else
            <x-dashboard.section :title="__('Registro de dispensación')" :count="$count">
                <div class="csc-table-wrap" tabindex="0" role="region" aria-label="{{ __('Registro de dispensación') }}">
                    <table class="csc-table csc-rep-table">
                        <thead>
                            <tr>
                                <th>{{ __('Fecha') }}</th>
                                <th>{{ __('Nº socio') }}</th>
                                <th>{{ __('Genética') }}</th>
                                <th>{{ __('Lote') }}</th>
                                <th class="csc-num">{{ __('Gramos') }}</th>
                                <th class="csc-num">{{ __('Aportación') }}</th>
                                <th>{{ __('Operador') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    <td>{{ \Illuminate\Support\Carbon::parse($row['fecha'])->format('d/m/Y H:i') }}</td>
                                    <td>{{ $row['member_no'] }}</td>
                                    <td>{{ $row['genetica'] }}</td>
                                    <td>{{ $row['lote'] }}</td>
                                    <td class="csc-num">{{ $row['grams'] }}</td>
                                    <td class="csc-num">{{ $row['total'] }}</td>
                                    <td>{{ $row['operador'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-dashboard.section>
        @endif
    </div>

    @include('filament.pages.partials.dashboard-styles')
    @include('filament.pages.partials.report-styles')
</x-filament-panels::page>
