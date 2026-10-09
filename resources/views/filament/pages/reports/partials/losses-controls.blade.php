{{-- Prompt 367 — the detail's filters (section, person) and pager, right above the detail. A section card or a person's name
     links here with the filter set; the CSV is always the whole report. --}}
<div id="losses-detail" data-losses-detail="{{ $section ?? 'all' }}" class="flex flex-wrap items-end justify-start gap-3">
    <label class="text-sm">
        <span class="block text-gray-600 dark:text-gray-400">{{ __('Sección') }}</span>
        <select wire:model.live="section" class="fi-input mt-1 block min-h-11 rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-white/10 dark:bg-white/5">
            <option value="">{{ __('Todas') }}</option>
            @foreach ($lossSectionTitles as $key => $title)
                <option value="{{ $key }}">{{ $title }}</option>
            @endforeach
        </select>
    </label>
    <label class="text-sm">
        <span class="block text-gray-600 dark:text-gray-400">{{ __('Persona') }}</span>
        <select wire:model.live="person" class="fi-input mt-1 block min-h-11 rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-white/10 dark:bg-white/5">
            <option value="">{{ __('Todas') }}</option>
            @foreach ($lossPeople as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    </label>
    @if ($lossPages['pages'] > 1)
        <div class="flex items-center gap-2 text-sm" data-losses-pager>
            <x-filament::button size="sm" color="gray" wire:click="goToDetailPage({{ $lossPages['page'] - 1 }})" :disabled="$lossPages['page'] <= 1">{{ __('Anterior') }}</x-filament::button>
            <span>{{ __('Página :page de :pages', ['page' => $lossPages['page'], 'pages' => $lossPages['pages']]) }}</span>
            <x-filament::button size="sm" color="gray" wire:click="goToDetailPage({{ $lossPages['page'] + 1 }})" :disabled="$lossPages['page'] >= $lossPages['pages']">{{ __('Siguiente') }}</x-filament::button>
        </div>
    @endif
</div>
