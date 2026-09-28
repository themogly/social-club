{{-- Prompt 281 (Ben's 280) — Registro de jornada. Read through WorkedHours only; a correction is always a new row. --}}
<x-filament-panels::page>
    <p class="text-sm text-gray-600 dark:text-gray-400">
        {{ __('Cada entrada y salida se registra con el PIN de la propia persona y no se puede editar: una corrección añade un fichaje nuevo con su motivo, y el original se conserva.') }}
    </p>

    <div class="flex flex-wrap items-end gap-3">
        <label class="text-sm">
            <span class="block text-gray-600 dark:text-gray-400">{{ __('Mes') }}</span>
            <input type="month" wire:model.live="month" class="fi-input mt-1 block min-h-11 rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-white/10 dark:bg-white/5">
        </label>
        <label class="text-sm">
            <span class="block text-gray-600 dark:text-gray-400">{{ __('Sede') }}</span>
            <select wire:model.live="locationId" class="fi-input mt-1 block min-h-11 rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-white/10 dark:bg-white/5">
                <option value="">{{ __('Todas') }}</option>
                @foreach ($this->sedeOptions() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm">
            <span class="block text-gray-600 dark:text-gray-400">{{ __('Persona') }}</span>
            <select wire:model.live="personId" class="fi-input mt-1 block min-h-11 rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-white/10 dark:bg-white/5">
                <option value="">{{ __('Todas') }}</option>
                @foreach ($this->peopleOptions() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
    </div>

    @php($rows = collect($this->reportRows())->groupBy('user_id'))
    @forelse ($rows as $personRows)
        <x-filament::section :heading="$personRows->first()['name']"
            :description="__('Total del mes: :total', ['total' => sprintf('%d h %02d min', intdiv((int) $personRows->where('kind', 'period')->sum('minutes'), 60), (int) $personRows->where('kind', 'period')->sum('minutes') % 60)])">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[40rem] text-sm" data-jornada-person>
                    <thead>
                        <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                            <th class="py-2 pe-3 font-medium">{{ __('Fecha') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Sede') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Entrada') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Salida') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Total') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Avisos') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($personRows as $row)
                            <tr data-jornada-row="{{ $row['kind'] }}" @class(['line-through text-gray-500' => $row['kind'] === 'annulled'])>
                                <td class="py-2 pe-3">{{ \Carbon\CarbonImmutable::parse($row['business_date'])->translatedFormat('D j M') }}</td>
                                <td class="px-3 py-2">{{ $row['location'] }}</td>
                                <td class="px-3 py-2 tabular-nums">{{ $row['in'] ? local_datetime($row['in']->occurred_at, 'H:i', $row['in']->location) : '—' }}</td>
                                <td class="px-3 py-2 tabular-nums">{{ $row['out'] ? local_datetime($row['out']->occurred_at, 'H:i', $row['out']->location) : '—' }}</td>
                                <td class="px-3 py-2 tabular-nums">{{ $row['minutes'] !== null ? sprintf('%d:%02d', intdiv($row['minutes'], 60), $row['minutes'] % 60) : '—' }}</td>
                                <td class="px-3 py-2">
                                    @foreach ($row['flags'] as $flag)
                                        <x-filament::badge color="warning" size="sm" class="inline-flex">{{ $flag }}</x-filament::badge>
                                    @endforeach
                                </td>
                                <td class="px-3 py-2 text-right">
                                    @if ($row['kind'] === 'period' && $this->canManage())
                                        @if ($row['out'] === null)
                                            {{ ($this->addOutAction)(['event' => $row['in']->id]) }}
                                        @endif
                                        {{ ($this->annulAction)(['event' => ($row['out'] ?? $row['in'])->id]) }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @empty
        <x-filament::section>
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('No hay fichajes en este mes para las sedes que puedes ver.') }}</p>
        </x-filament::section>
    @endforelse

    <x-filament-actions::modals />
</x-filament-panels::page>
