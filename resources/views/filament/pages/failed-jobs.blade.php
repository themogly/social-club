{{-- Trabajos fallidos — dead-letter view over failed_jobs, keyed by UUID (never numeric id). --}}
<x-filament-panels::page>
    @if (count($rows) === 0)
        <x-filament::section>
            <div style="text-align:center;padding:2.5rem 1rem;">
                <x-filament::icon icon="heroicon-o-check-circle" class="text-success" style="width:2.5rem;height:2.5rem;margin:0 auto .5rem;" />
                <p style="font-weight:600;">{{ __('No hay trabajos fallidos') }}</p>
                <p style="font-size:.85rem;opacity:.65;">{{ __('Cuando un trabajo en cola falle tras agotar sus reintentos, aparecerá aquí para reintentarlo o descartarlo.') }}</p>
            </div>
        </x-filament::section>
    @else
        <x-filament::section>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
                    <thead>
                        <tr class="border-b border-line text-left dark:border-slate-800">
                            <th style="padding:.5rem .6rem;">{{ __('Trabajo') }}</th>
                            <th style="padding:.5rem .6rem;">{{ __('Cola') }}</th>
                            <th style="padding:.5rem .6rem;">{{ __('Fallo') }}</th>
                            <th style="padding:.5rem .6rem;">{{ __('Error') }}</th>
                            <th style="padding:.5rem .6rem;text-align:right;">{{ __('Acciones') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-b border-line align-top dark:border-slate-800">
                                <td style="padding:.5rem .6rem;">
                                    <div style="font-weight:600;">{{ $row['name'] }}</div>
                                    <div style="font-size:.7rem;opacity:.55;font-variant-numeric:tabular-nums;">{{ $row['uuid'] }}</div>
                                </td>
                                <td style="padding:.5rem .6rem;">{{ $row['connection'] }} · {{ $row['queue'] }}</td>
                                <td style="padding:.5rem .6rem;white-space:nowrap;">{{ $row['failed_at'] }}</td>
                                <td style="padding:.5rem .6rem;max-width:28rem;"><span class="text-error">{{ $row['exception'] }}</span></td>
                                <td style="padding:.5rem .6rem;text-align:right;white-space:nowrap;">
                                    <span style="display:inline-flex;gap:.4rem;">
                                        <x-filament::button size="xs" color="gray" icon="heroicon-o-arrow-path"
                                            wire:click="retry(@js($row['uuid']))" wire:target="retry(@js($row['uuid']))">
                                            {{ __('Reintentar') }}
                                        </x-filament::button>
                                        <x-filament::button size="xs" color="danger" icon="heroicon-o-trash"
                                            wire:click="forget(@js($row['uuid']))"
                                            wire:confirm="{{ __('¿Descartar definitivamente este trabajo?') }}">
                                            {{ __('Descartar') }}
                                        </x-filament::button>
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
