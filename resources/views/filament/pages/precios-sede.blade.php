{{-- Prompt 382 — Precios de la sede: the three price lists of every open batch with stock at one sede, priced in one go.
     A table from xl up; one card per batch below (two columns on a tablet). A blank Local / Personal cell shows its default in grey; a cell
     below cost is amber (a warning, never a block). Quick fills only fill; «Guardar todo» saves the changed batches. --}}
<x-filament-panels::page>
    @php
        $sheet = $this->sheet();
        $rows = $this->visibleRows();
        $lists = \App\Enums\PriceList::cases();
        $dirty = $this->isDirty();
    @endphp

    <p class="text-sm text-gray-600 dark:text-gray-400">
        {{ __('Cada tarifa paga una de tres listas de precios. Un precio Local o Personal vacío es el Estándar menos el descuento por defecto (:local % Local, :staff % Personal). Los cambios se aplican en el mostrador en la siguiente cesta.', ['local' => \App\Models\Batch::defaultPercent(\App\Enums\PriceList::LOCAL), 'staff' => \App\Models\Batch::defaultPercent(\App\Enums\PriceList::STAFF)]) }}
    </p>

    {{-- The sede, the filters and Guardar todo. --}}
    <div class="flex flex-wrap items-end gap-3">
        <label class="text-sm">
            <span class="block text-gray-600 dark:text-gray-400">{{ __('Sede') }}</span>
            <x-filament::input.wrapper class="mt-1 w-56 max-w-full">
                <x-filament::input.select wire:model.live="sede" data-prices-sede>
                    @foreach ($this->sedeOptions() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
        <label class="text-sm">
            <span class="block text-gray-600 dark:text-gray-400">{{ __('Buscar') }}</span>
            <x-filament::input.wrapper class="mt-1 w-56 max-w-full">
                <x-filament::input type="search" wire:model.live.debounce.250ms="search" :placeholder="__('Nombre de la genética')" data-prices-search />
            </x-filament::input.wrapper>
        </label>
        <label class="flex min-h-11 items-center gap-2 text-sm">
            <x-filament::input.checkbox wire:model.live="onlyUnpriced" />
            {{ __('Solo sin precio') }}
        </label>
        <label class="flex min-h-11 items-center gap-2 text-sm">
            <x-filament::input.checkbox wire:model.live="onlyBelowCost" />
            {{ __('Solo por debajo del coste') }}
        </label>
        <div class="ms-auto flex items-center gap-3">
            @if ($dirty)
                <span class="text-sm font-medium text-warning-600 dark:text-warning-400" data-prices-dirty>{{ __('Hay cambios sin guardar') }}</span>
            @endif
            <x-filament::button wire:click="save" icon="heroicon-o-check" data-prices-save>{{ __('Guardar todo') }}</x-filament::button>
        </div>
    </div>

    {{-- Quick fills: applied to the selected rows, or to every row shown. They fill the cells; nothing is saved until Guardar todo. --}}
    <div x-data="{ open: false }" class="-mt-2">
        <x-filament::button color="gray" size="sm" icon="heroicon-o-bolt" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-controls="prices-quick-fill" data-prices-quick-fill-toggle>
            {{ __('Rellenar rápido') }}
        </x-filament::button>
        <div id="prices-quick-fill" x-show="open" x-cloak class="mt-2 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900" data-prices-quick-fill>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ count($selected) > 0 ? trans_choice('Se aplica a :count lote marcado.|Se aplica a los :count lotes marcados.', count($selected), ['count' => count($selected)]) : __('Se aplica a todos los lotes de la lista (marca algunos para limitarlo).') }}
                {{ __('Rellena las casillas; no se guarda hasta «Guardar todo».') }}
            </p>
            <div class="mt-3 flex flex-wrap items-end gap-3">
                <label class="text-sm">
                    <span class="block text-gray-600 dark:text-gray-400">{{ __('Descuento (%)') }}</span>
                    <x-filament::input.wrapper class="mt-1 w-24" :valid="! $errors->has('fillPct')">
                        <x-filament::input type="text" inputmode="decimal" wire:model="fillPct" class="text-right tabular-nums" data-prices-fill-pct />
                    </x-filament::input.wrapper>
                </label>
                <x-filament::button size="sm" color="gray" wire:click="fillFromStandard('LOCAL')" data-prices-fill="LOCAL">{{ __('Local = Estándar −:pct %', ['pct' => $fillPct]) }}</x-filament::button>
                <x-filament::button size="sm" color="gray" wire:click="fillFromStandard('STAFF')" data-prices-fill="STAFF">{{ __('Personal = Estándar −:pct %', ['pct' => $fillPct]) }}</x-filament::button>
                <x-filament::button size="sm" color="gray" wire:click="clearLists" data-prices-clear>{{ __('Vaciar Local / Personal') }}</x-filament::button>
            </div>
            @error('fillPct')<p class="mt-1 text-sm text-danger-600 dark:text-danger-400" role="alert">{{ $message }}</p>@enderror
            @if ($this->copyOptions() !== [])
                <div class="mt-3 flex flex-wrap items-end gap-3 border-t border-gray-100 pt-3 dark:border-white/5">
                    <label class="text-sm">
                        <span class="block text-gray-600 dark:text-gray-400">{{ __('Copiar precios de otra sede') }}</span>
                        <x-filament::input.wrapper class="mt-1 w-56 max-w-full" :valid="! $errors->has('copyFrom')">
                            <x-filament::input.select wire:model="copyFrom" data-prices-copy-from>
                                <option value="">{{ __('Elige una sede') }}</option>
                                @foreach ($this->copyOptions() as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>
                    <x-filament::button size="sm" color="gray" wire:click="copyFromSede" data-prices-copy>{{ __('Copiar') }}</x-filament::button>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Para la misma genética, los precios del lote que esa sede tiene ahora.') }}</p>
                </div>
                @error('copyFrom')<p class="mt-1 text-sm text-danger-600 dark:text-danger-400" role="alert">{{ $message }}</p>@enderror
            @endif
        </div>
    </div>

    @if ($sheet === null || $sheet->batches() === [])
        <x-filament::section>
            <div class="py-6 text-center" data-prices-empty>
                <p class="font-medium text-gray-950 dark:text-white">{{ __('No hay lotes abiertos con existencias en esta sede') }}</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Cuando entre stock, sus lotes aparecerán aquí para ponerles precio.') }}</p>
                <x-filament::button tag="a" size="sm" color="gray" class="mt-3" :href="\App\Filament\Resources\Batches\BatchResource::getUrl('index')">{{ __('Ir a Lotes') }}</x-filament::button>
            </div>
        </x-filament::section>
    @elseif ($rows === [])
        <x-filament::section>
            <p class="py-4 text-center text-sm text-gray-600 dark:text-gray-400" data-prices-no-match>{{ __('Ningún lote coincide con los filtros.') }}</p>
        </x-filament::section>
    @else
        {{-- Wide screens: the table (below 1280 px its nine price columns would scroll). --}}
        <x-filament::section class="hidden xl:block">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[50rem] table-fixed text-sm" data-prices-table>
                    <thead>
                        <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                            <th class="w-8 py-2 pe-2" rowspan="2"><span class="sr-only">{{ __('Marcar') }}</span></th>
                            <th class="w-40 py-2 pe-3 font-medium" rowspan="2">{{ __('Genética') }} <span class="font-normal">· {{ __('Existencias') }} · {{ __('Coste/g') }}</span></th>
                            @foreach ($lists as $list)
                                <th class="px-2 pt-2 text-center font-semibold text-gray-700 dark:text-gray-200" colspan="2" data-prices-list-head="{{ $list->value }}">{{ $list->label() }}</th>
                            @endforeach
                        </tr>
                        <tr class="text-xs text-gray-500 dark:text-gray-400">
                            @foreach ($lists as $list)
                                <th class="px-2 pb-2 text-center font-medium">{{ __('g') }}</th>
                                <th class="px-2 pb-2 text-center font-medium">{{ __('3.5 g') }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($rows as $row)
                            <tr wire:key="price-row-{{ $row['id'] }}" data-prices-row="{{ $row['id'] }}" class="align-top">
                                <td class="py-3 pe-2 ps-1"><x-filament::input.checkbox wire:model="selected" value="{{ $row['id'] }}" :aria-label="__('Marcar :name', ['name' => $row['strain']])" /></td>
                                <td class="py-3 pe-3">
                                    <a href="{{ $this->batchUrl($row['batch']) }}" class="font-medium text-gray-950 hover:underline dark:text-white">{{ $row['strain'] }}</a>
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $row['label'] }}</span>
                                    <span class="block whitespace-nowrap text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ $row['stock'] }} · {{ __('Coste/g') }} {{ $row['cost'] ?? '—' }}</span>
                                </td>
                                @foreach ($lists as $list)
                                    @if ($row['unit'])
                                        <td class="px-1 py-2" colspan="2">@include('filament.pages.partials.precios-cell', ['row' => $row, 'c' => $row['cells'][$list->value]['unit'], 'label' => $list->label().' · '.__('unidad')])</td>
                                    @else
                                        <td class="px-1 py-2">@include('filament.pages.partials.precios-cell', ['row' => $row, 'c' => $row['cells'][$list->value]['gram'], 'label' => $list->label().' · '.__('g')])</td>
                                        <td class="px-1 py-2">@include('filament.pages.partials.precios-cell', ['row' => $row, 'c' => $row['cells'][$list->value]['eighth'], 'label' => $list->label().' · '.__('3.5 g')])</td>
                                    @endif
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        {{-- Narrower: one card per batch, two columns on a tablet. --}}
        <div class="grid gap-3 md:grid-cols-2 xl:hidden" data-prices-cards>
            @foreach ($rows as $row)
                <x-filament::section wire:key="price-card-{{ $row['id'] }}" data-prices-card="{{ $row['id'] }}">
                    <div class="flex items-start gap-3">
                        <x-filament::input.checkbox wire:model="selected" value="{{ $row['id'] }}" class="mt-1" :aria-label="__('Marcar :name', ['name' => $row['strain']])" />
                        <div class="min-w-0 flex-1">
                            <a href="{{ $this->batchUrl($row['batch']) }}" class="font-medium text-gray-950 hover:underline dark:text-white">{{ $row['strain'] }}</a>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $row['label'] }} · {{ $row['stock'] }} · {{ __('Coste/g') }} {{ $row['cost'] ?? '—' }}</p>
                        </div>
                    </div>
                    <div class="mt-3 grid gap-3">
                        @foreach ($lists as $list)
                            <div>
                                <p class="text-xs font-semibold text-gray-700 dark:text-gray-200">{{ $list->label() }}</p>
                                <div class="mt-1 grid {{ $row['unit'] ? 'grid-cols-1' : 'grid-cols-2' }} gap-2">
                                    @if ($row['unit'])
                                        @include('filament.pages.partials.precios-cell', ['row' => $row, 'c' => $row['cells'][$list->value]['unit'], 'label' => $list->label().' · '.__('unidad')])
                                    @else
                                        @include('filament.pages.partials.precios-cell', ['row' => $row, 'c' => $row['cells'][$list->value]['gram'], 'label' => $list->label().' · '.__('g')])
                                        @include('filament.pages.partials.precios-cell', ['row' => $row, 'c' => $row['cells'][$list->value]['eighth'], 'label' => $list->label().' · '.__('3.5 g')])
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @endforeach
        </div>

        <div class="flex justify-end">
            <x-filament::button wire:click="save" icon="heroicon-o-check">{{ __('Guardar todo') }}</x-filament::button>
        </div>
    @endif
</x-filament-panels::page>
