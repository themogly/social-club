{{-- Prompt 318 — Inventario: the list of counts, one count (blind by default, saved line by line), and its review. --}}
<x-filament-panels::page>
    @if ($count === null)
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('Un inventario cuenta todo lo que hay en una sede: cada lote con existencias y cada producto activo. Se puede hacer en varias veces; la barra y el dispensario siguen funcionando mientras tanto. Nada cambia en las existencias hasta que se revisa y se pulsa «Aplicar ajustes».') }}
        </p>

        @php $counts = $this->counts(); @endphp
        @if ($counts === [])
            <x-filament::section>
                <div class="py-6 text-center" data-count-empty>
                    <p class="font-medium text-gray-950 dark:text-white">{{ __('Todavía no hay inventarios') }}</p>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Empieza uno con «Nuevo inventario»: elige la sede y ve contando.') }}</p>
                </div>
            </x-filament::section>
        @else
            <x-filament::section>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[36rem] text-sm" data-count-list>
                        <thead>
                            <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                                <th class="py-2 pe-3 font-medium">{{ __('Sede') }}</th>
                                <th class="px-3 py-2 font-medium">{{ __('Fecha') }}</th>
                                <th class="px-3 py-2 font-medium">{{ __('Estado') }}</th>
                                <th class="px-3 py-2 font-medium">{{ __('Líneas') }}</th>
                                <th class="px-3 py-2 font-medium">{{ __('Diferencia neta') }}</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($counts as $item)
                                @php $t = $item['take']; $totals = $item['totals']; @endphp
                                <tr data-count-row="{{ $t->status->value }}">
                                    <td class="py-2 pe-3 font-medium">{{ $t->location?->name }}</td>
                                    <td class="px-3 py-2 tabular-nums">{{ local_datetime($t->opened_at, 'd/m/Y H:i', $t->location) }}</td>
                                    <td class="px-3 py-2">
                                        <x-filament::badge :color="match ($t->status) { \App\Enums\StockTakeStatus::OPEN => 'warning', \App\Enums\StockTakeStatus::COMMITTED => 'success', default => 'gray' }" class="inline-flex">{{ $t->status->label() }}</x-filament::badge>
                                    </td>
                                    <td class="px-3 py-2 tabular-nums">{{ $totals['settled'] }} / {{ $totals['lines'] }}</td>
                                    <td class="px-3 py-2 tabular-nums">
                                        {{ $totals['net_weight'] }} · {{ $totals['net_units_text'] }} · {{ $totals['net_value'] }}
                                    </td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap">
                                        <x-filament::button tag="a" size="sm" color="gray" :href="\App\Filament\Pages\Inventario::getUrl(['count' => $t->id, 'mode' => $t->isOpen() ? 'count' : 'review'])">
                                            {{ $t->isOpen() ? __('Seguir contando') : __('Ver') }}
                                        </x-filament::button>
                                        @if ($t->status === \App\Enums\StockTakeStatus::COMMITTED)
                                            <x-filament::button size="sm" color="gray" wire:click="downloadReport('{{ $t->id }}')">{{ __('PDF') }}</x-filament::button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    @else
        @php
            $take = $this->take();
            $sheet = $this->sheet();
            $totals = $sheet->totals();
            $open = $take->isOpen();
        @endphp

        <div class="flex flex-wrap items-center gap-3">
            <x-filament::badge :color="$open ? 'warning' : ($take->status === \App\Enums\StockTakeStatus::COMMITTED ? 'success' : 'gray')" class="inline-flex">{{ $take->status->label() }}</x-filament::badge>
            <span class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('Abierto el :date por :name', ['date' => local_datetime($take->opened_at, 'd/m/Y H:i', $take->location), 'name' => (string) $take->openedBy?->name]) }}
                @if ($take->committed_at) · {{ __('Aplicado el :date por :name', ['date' => local_datetime($take->committed_at, 'd/m/Y H:i', $take->location), 'name' => (string) $take->committedBy?->name]) }}@endif
            </span>
            <span class="text-sm font-medium tabular-nums" data-count-progress>{{ __(':done de :total líneas', ['done' => $totals['settled'], 'total' => $totals['lines']]) }}</span>
        </div>

        <div class="flex gap-2" role="tablist">
            @foreach (['count' => __('Contar'), 'review' => __('Revisar')] as $key => $label)
                <x-filament::button size="sm" :color="$mode === $key ? 'primary' : 'gray'" wire:click="$set('mode', '{{ $key }}')" role="tab" :aria-selected="$mode === $key ? 'true' : 'false'">{{ $label }}</x-filament::button>
            @endforeach
            @if ($take->status === \App\Enums\StockTakeStatus::COMMITTED)
                <x-filament::button size="sm" color="gray" wire:click="downloadReport('{{ $take->id }}')">{{ __('Informe (PDF)') }}</x-filament::button>
            @endif
        </div>

        @if ($mode === 'count')
            <div class="flex flex-wrap items-end gap-3">
                <label class="text-sm">
                    <span class="block text-gray-600 dark:text-gray-400">{{ __('Buscar') }}</span>
                    <x-filament::input.wrapper class="mt-1 w-72 max-w-full">
                        <x-filament::input type="search" wire:model.live.debounce.250ms="lineFilter" :placeholder="__('Nombre del lote o producto')" />
                    </x-filament::input.wrapper>
                </label>
                <label class="flex min-h-11 items-center gap-2 text-sm">
                    <x-filament::input.checkbox wire:model.live="pendingOnly" />
                    {{ __('Solo pendientes') }}
                </label>
                @unless ($sheet->showsExpected())
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Conteo a ciegas: pesa y escribe lo que hay. La cifra del sistema se ve al revisar.') }}</p>
                @endunless
            </div>

            @forelse ($this->visibleGroups() as $group)
                <x-filament::section :heading="$group['name']">
                    <ul class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($group['rows'] as $row)
                            <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-3" data-count-line="{{ $row['id'] }}" wire:key="line-{{ $row['id'] }}" x-data="{ skip: false }">
                                <div class="min-w-48 flex-1">
                                    <p class="font-medium text-gray-950 dark:text-white">{{ $row['name'] }}@if ($row['reference']) <span class="text-xs font-normal text-gray-500 dark:text-gray-400">· {{ $row['reference'] }}</span>@endif</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        @if ($sheet->showsExpected() && $row['current'] !== null)
                                            <span data-count-system>{{ __('Sistema: :qty', ['qty' => $row['current']]) }}</span> ·
                                        @endif
                                        @if ($row['not_counted'])
                                            <span class="text-warning-600 dark:text-warning-400">{{ __('No contado: :reason', ['reason' => $row['not_counted_reason']]) }}</span>
                                        @elseif ($row['settled'])
                                            <span class="text-success-600 dark:text-success-400" data-count-saved>{{ __('Contado :qty · :name · :time', ['qty' => $row['counted'], 'name' => (string) $row['counted_by'], 'time' => (string) $row['counted_at']]) }}</span>
                                        @else
                                            {{ __('Pendiente') }}
                                        @endif
                                    </p>
                                </div>
                                @if ($open)
                                    <form class="ms-auto flex items-center gap-2" wire:submit="saveLine('{{ $row['id'] }}')" x-show="! skip">
                                        <label class="sr-only" for="entry-{{ $row['id'] }}">{{ __('Cantidad contada de :item', ['item' => $row['name']]) }}</label>
                                        <x-filament::input.wrapper class="w-28" :valid="! $errors->has('entries.'.$row['id'])">
                                            <x-filament::input :id="'entry-'.$row['id']" type="text" :inputmode="$row['unit'] ? 'numeric' : 'decimal'" autocomplete="off"
                                                wire:model="entries.{{ $row['id'] }}" :placeholder="$row['unit'] ? '0' : '0.00'" class="text-right tabular-nums" />
                                        </x-filament::input.wrapper>
                                        <span class="w-6 text-sm text-gray-500 dark:text-gray-400">{{ $row['unit'] ? __('ud.') : 'g' }}</span>
                                        <x-filament::button type="submit" size="sm">{{ $row['settled'] && ! $row['not_counted'] ? __('Recontar') : __('Guardar') }}</x-filament::button>
                                        <x-filament::button type="button" size="sm" color="gray" x-on:click="skip = true">{{ __('No contado') }}</x-filament::button>
                                    </form>
                                    <form class="ms-auto flex items-center gap-2" wire:submit="markNotCounted('{{ $row['id'] }}')" x-show="skip" x-cloak>
                                        <label class="sr-only" for="skip-{{ $row['id'] }}">{{ __('Motivo') }}</label>
                                        <x-filament::input.wrapper class="w-56">
                                            <x-filament::input :id="'skip-'.$row['id']" type="text" wire:model="notCounted.{{ $row['id'] }}" :placeholder="__('Por qué no se cuenta')" />
                                        </x-filament::input.wrapper>
                                        <x-filament::button type="submit" size="sm" color="warning">{{ __('Marcar') }}</x-filament::button>
                                        <x-filament::button type="button" size="sm" color="gray" x-on:click="skip = false">{{ __('Volver') }}</x-filament::button>
                                    </form>
                                @endif
                                @error('entries.'.$row['id'])
                                    <p class="w-full text-sm text-danger-600 dark:text-danger-400" role="alert">{{ $message }}</p>
                                @enderror
                            </li>
                        @endforeach
                    </ul>
                </x-filament::section>
            @empty
                <x-filament::section>
                    <p class="py-4 text-center text-sm text-gray-600 dark:text-gray-400">
                        {{ $pendingOnly && $lineFilter === '' ? __('No queda nada por contar. Pasa a «Revisar».') : __('Nada coincide con la búsqueda.') }}
                    </p>
                </x-filament::section>
            @endforelse
        @else
            @include('filament.pages.partials.inventario-review')
        @endif
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page>
