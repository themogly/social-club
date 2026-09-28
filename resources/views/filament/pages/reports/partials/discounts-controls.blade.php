{{-- Prompt 291 — the detail's filters (kind, operator) and pager. The detail can be long (member discounts, grouped per
     sale), so it pages; the CSV of the per-operator table is always complete. --}}
<div class="csc-section" data-discounts-controls>
<div class="flex flex-wrap items-end justify-start gap-3">
    <label class="text-sm">
        <span class="block text-gray-600 dark:text-gray-400">{{ __('Detalle: tipo') }}</span>
        <select wire:model.live="kind" class="fi-input mt-1 block min-h-11 rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-white/10 dark:bg-white/5">
            <option value="">{{ __('Todos') }}</option>
            <option value="ajuste">{{ __('Ajuste') }}</option>
            <option value="condonacion">{{ __('Condonación') }}</option>
            <option value="manual">{{ __('Línea manual') }}</option>
            <option value="descuento">{{ __('Descuento de socio') }}</option>
        </select>
    </label>
    <label class="text-sm">
        <span class="block text-gray-600 dark:text-gray-400">{{ __('Detalle: operador') }}</span>
        <select wire:model.live="operator" class="fi-input mt-1 block min-h-11 rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-white/10 dark:bg-white/5">
            <option value="">{{ __('Todos') }}</option>
            @foreach ($discountOperators as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    </label>
    @if ($discountPages['pages'] > 1)
        <div class="flex items-center gap-2 text-sm" data-discounts-pager>
            <x-filament::button size="sm" color="gray" wire:click="goToDetailPage({{ $discountPages['page'] - 1 }})" :disabled="$discountPages['page'] <= 1">{{ __('Anterior') }}</x-filament::button>
            <span>{{ __('Página :page de :pages', ['page' => $discountPages['page'], 'pages' => $discountPages['pages']]) }}</span>
            <x-filament::button size="sm" color="gray" wire:click="goToDetailPage({{ $discountPages['page'] + 1 }})" :disabled="$discountPages['page'] >= $discountPages['pages']">{{ __('Siguiente') }}</x-filament::button>
        </div>
    @endif
</div>
</div>
