{{--
    Libro de socios — the statutory register "as at" a date, wrapping MembersRegister::asAt.
    Reuses the dashboard/report .csc-dash tokens (theme-aware via Filament's .dark class).
--}}
<x-filament-panels::page>
    <div class="csc-dash csc-report" wire:key="libro-{{ $asAt }}-{{ $sedeLabel }}">
        <div class="csc-toolbar">
            <label class="csc-date">
                <span>{{ __('A fecha de') }}</span>
                <input type="date" wire:model.live="asAt" max="{{ now()->toDateString() }}">
            </label>

            @if (count($sedeOptions) > 0)
                <label class="csc-date csc-scope">
                    <span>{{ __('Sede') }}</span>
                    <select wire:model.live="sede" class="csc-select">
                        <option value="all">{{ __('Todas las sedes') }}</option>
                        @foreach ($sedeOptions as $value => $label)
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

        <p class="csc-rep-context">
            {{ $sedeLabel }} · {{ __('a fecha de :date', ['date' => \Illuminate\Support\Carbon::parse($asAt)->format('d/m/Y')]) }}
            · {{ trans_choice(':count socio|:count socios', $count, ['count' => $count]) }}
        </p>

        @if ($count === 0)
            <div class="csc-section">
                <div class="csc-empty csc-empty-hero">
                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedUserGroup" class="csc-empty-ico" />
                    <p class="csc-empty-msg">{{ __('Sin socios a esta fecha') }}</p>
                    <p class="csc-empty-hint">{{ __('El libro recoge a quienes se habían dado de alta en la fecha elegida, incluidas las bajas. Da de alta socios o cambia la fecha.') }}</p>
                </div>
            </div>
        @else
            <x-dashboard.section :title="__('Libro de socios')" :count="$count">
                <div class="csc-table-wrap">
                    <table class="csc-table csc-rep-table">
                        <thead>
                            <tr>
                                <th>{{ __('Nº socio') }}</th>
                                <th>{{ __('Nombre') }}</th>
                                <th>{{ __('Documento') }}</th>
                                <th>{{ __('Alta') }}</th>
                                <th>{{ __('Baja') }}</th>
                                <th>{{ __('Estado') }}</th>
                                <th>{{ __('Sede') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    <td>{{ $row['member_no'] ?? '—' }}</td>
                                    <td>{{ $row['nombre'] ?? '—' }}</td>
                                    <td>{{ $row['documento'] ?? '—' }}</td>
                                    <td>{{ $row['alta'] ?? '—' }}</td>
                                    <td>{{ $row['baja'] ?? '—' }}</td>
                                    <td>{{ $row['estado'] ?? '—' }}</td>
                                    <td>{{ $row['sede'] ?? '—' }}</td>
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
