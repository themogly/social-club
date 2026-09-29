{{-- Prompt 318 — the review of an Inventario: totals, the differences largest first (reasons above the tolerance), what was not counted, and «Aplicar ajustes». --}}
@php $differences = $sheet->differences(); @endphp

<x-filament::section>
    <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4" data-count-totals>
        <div><dt class="text-gray-500 dark:text-gray-400">{{ __('Líneas contadas') }}</dt><dd class="text-lg font-semibold tabular-nums">{{ $totals['settled'] - $totals['not_counted'] }} / {{ $totals['lines'] }}</dd></div>
        <div><dt class="text-gray-500 dark:text-gray-400">{{ __('Con diferencia') }}</dt><dd class="text-lg font-semibold tabular-nums">{{ $totals['differences'] }}</dd></div>
        <div><dt class="text-gray-500 dark:text-gray-400">{{ __('Diferencia neta') }}</dt><dd class="text-lg font-semibold tabular-nums">{{ $totals['net_weight'] }} · {{ $totals['net_units_text'] }}</dd></div>
        <div><dt class="text-gray-500 dark:text-gray-400">{{ __('Valor (a la aportación)') }}</dt><dd class="text-lg font-semibold tabular-nums">{{ $totals['net_value'] }}</dd></div>
    </dl>
    @if ($open && $totals['pending'] > 0)
        <p class="mt-4 text-sm text-warning-600 dark:text-warning-400" data-count-pending>
            {{ trans_choice('Queda :count línea sin contar. Cuéntala o márcala como «No contado» antes de aplicar.|Quedan :count líneas sin contar. Cuéntalas o márcalas como «No contado» antes de aplicar.', $totals['pending'], ['count' => $totals['pending']]) }}
        </p>
    @endif
</x-filament::section>

<x-filament::section :heading="__('Diferencias')" :description="$open ? __('Por encima de la tolerancia, una diferencia necesita un motivo y una nota. La diferencia es contra lo que había en el sistema cuando se contó cada línea.') : null">
    @if ($differences === [])
        <p class="py-4 text-center text-sm text-gray-600 dark:text-gray-400">{{ __('Ninguna línea contada tiene diferencia.') }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full min-w-[44rem] text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                        <th class="py-2 pe-3 font-medium">{{ __('Lote o producto') }}</th>
                        <th class="px-3 py-2 text-right font-medium">{{ __('Sistema') }}</th>
                        <th class="px-3 py-2 text-right font-medium">{{ __('Contado') }}</th>
                        <th class="px-3 py-2 text-right font-medium">{{ __('Diferencia') }}</th>
                        <th class="px-3 py-2 text-right font-medium">{{ __('Valor') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('Motivo') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($differences as $row)
                        <tr data-count-difference="{{ $row['id'] }}" wire:key="diff-{{ $row['id'] }}">
                            <td class="py-2 pe-3">
                                <span class="font-medium">{{ $row['name'] }}</span>@if ($row['reference']) <span class="text-xs text-gray-500 dark:text-gray-400">· {{ $row['reference'] }}</span>@endif
                                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $row['counted_by'] }} · {{ $row['counted_at'] }}</span>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums whitespace-nowrap">{{ $row['expected'] }}</td>
                            <td class="px-3 py-2 text-right tabular-nums whitespace-nowrap">{{ $row['counted'] }}</td>
                            <td @class(['px-3 py-2 text-right font-semibold tabular-nums whitespace-nowrap', 'text-danger-600 dark:text-danger-400' => $row['difference'] < 0, 'text-success-600 dark:text-success-400' => $row['difference'] > 0])>{{ $row['difference_text'] }}</td>
                            <td class="px-3 py-2 text-right tabular-nums whitespace-nowrap">{{ $row['value_text'] }}</td>
                            <td class="px-3 py-2">
                                @if ($open)
                                    <div class="flex flex-wrap gap-2">
                                        <label class="sr-only" for="reason-{{ $row['id'] }}">{{ __('Motivo') }}</label>
                                        <x-filament::input.wrapper class="w-44">
                                            <x-filament::input.select :id="'reason-'.$row['id']" wire:model.live="reasons.{{ $row['id'] }}.reason">
                                                <option value="">{{ $row['needs_reason'] ? __('Elige un motivo') : __('Sin motivo') }}</option>
                                                @foreach ($this->reasonOptions() as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                        <label class="sr-only" for="note-{{ $row['id'] }}">{{ __('Nota') }}</label>
                                        <x-filament::input.wrapper class="w-48">
                                            <x-filament::input :id="'note-'.$row['id']" type="text" wire:model.blur="reasons.{{ $row['id'] }}.note" :placeholder="$row['needs_reason'] ? __('Nota (obligatoria)') : __('Nota')" />
                                        </x-filament::input.wrapper>
                                    </div>
                                    @if ($row['needs_reason'])
                                        <span class="mt-1 block text-xs text-warning-600 dark:text-warning-400" data-count-needs-reason>{{ __('Supera la tolerancia') }}</span>
                                    @endif
                                @else
                                    {{ $row['reason_label'] ?? '—' }}@if ($row['note']): {{ $row['note'] }}@endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament::section>

@php $skipped = array_values(array_filter($sheet->rows(), fn (array $row): bool => $row['not_counted'])); @endphp
@if ($skipped !== [])
    <x-filament::section :heading="__('No contado')" :description="__('Estas líneas no se tocan al aplicar.')">
        <ul class="divide-y divide-gray-100 text-sm dark:divide-white/5">
            @foreach ($skipped as $row)
                <li class="py-2"><span class="font-medium">{{ $row['name'] }}</span> — {{ $row['not_counted_reason'] }}</li>
            @endforeach
        </ul>
    </x-filament::section>
@endif

@if ($open)
    <div class="flex justify-end">{{ $this->applyAction }}</div>
@endif
