{{-- Prompt 289 — the tablets registered as counters. Revocar a lost or stolen one; its next request goes to the login. --}}
<x-filament-panels::page>
    <p class="text-sm text-gray-600 dark:text-gray-400">
        {{ __('Un mostrador registrado abre siempre el teclado de PIN, sin contraseña. No autoriza nada por sí mismo: cada acción sigue necesitando el PIN de quien trabaja.') }}
    </p>

    @php($terminals = $this->terminals())
    <x-filament::section>
        @if ($terminals->isEmpty())
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('No hay ningún mostrador registrado. Se registra desde el propio mostrador: botón «Este dispositivo» en la barra superior.') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[36rem] text-sm" data-counter-terminals>
                    <thead>
                        <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                            <th class="py-2 pe-3 font-medium">{{ __('Nombre') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Sede') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Registrado por') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Último uso') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($terminals as $terminal)
                            <tr data-counter-terminal-row>
                                <td class="py-2 pe-3 font-medium">{{ $terminal->name }}</td>
                                <td class="px-3 py-2">{{ $this->sedeName($terminal->location) }}</td>
                                <td class="px-3 py-2">{{ $terminal->registrar?->name ?? '—' }} · {{ local_datetime($terminal->registered_at, 'd/m/Y', $terminal->location) }}</td>
                                <td class="px-3 py-2">{{ $terminal->last_seen_at ? local_datetime($terminal->last_seen_at, 'd/m/Y H:i', $terminal->location) : '—' }}</td>
                                <td class="px-3 py-2 text-right">{{ ($this->revokeAction)(['terminal' => $terminal->id]) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-panels::page>
