{{-- Prompt 382 — one price cell on Precios de la sede: euros as typed (decimal point, 316); a blank Local / Personal cell shows
     its default in grey («8.80 · −20%»); below cost it turns amber with 295's wording — a warning, never a block.
     Prompt 383 — `.live.blur`: leaving the cell sends it, so the hint and the warning follow what was typed (`.blur` alone only
     synced on the client, and nothing recomputed until some other request). On a card, `caption` names the box (g / 3.5 g). --}}
@php($key = 'prices.'.$row['id'].'.'.$c['column'])
<div data-price-cell="{{ $c['column'] }}" @if ($c['below']) data-price-below-cost @endif>
    @if ($caption ?? null)<span class="mb-0.5 block text-[11px] font-medium text-gray-500 dark:text-gray-400" data-price-caption>{{ $caption }}</span>@endif
    <x-filament::input.wrapper :valid="! $errors->has($key)" @class(['ring-2 ring-warning-500 dark:ring-warning-400' => $c['below'] !== null])>
        <x-filament::input type="text" inputmode="decimal" autocomplete="off" wire:model.live.blur="{{ $key }}"
            :placeholder="$c['hint'] ?? ''" :aria-label="$row['strain'].' · '.$label" class="!px-1.5 !text-[13px] text-right tabular-nums placeholder:text-gray-400 dark:placeholder:text-gray-500" />
    </x-filament::input.wrapper>
    @if ($c['below'])
        <p class="mt-1 text-xs font-medium text-warning-600 dark:text-warning-400" title="{{ $c['below'] }}">{{ __('Por debajo del coste (:cost)', ['cost' => $c['below_cost']]) }}</p>
    @endif
    @error($key)<p class="mt-1 text-xs text-danger-600 dark:text-danger-400" role="alert">{{ $message }}</p>@enderror
</div>
