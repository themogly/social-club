<div class="flex items-center px-3">
    @if ($canSwitchToAll || $locations->count() > 1)
        <select
            wire:change="switchTo($event.target.value)"
            aria-label="{{ __('Sede activa') }}"
            class="fi-input block rounded-lg border border-gray-300 bg-white py-1.5 pl-3 pr-8 text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"
        >
            @if ($canSwitchToAll)
                <option value="" @selected($active === null)>{{ __('Todas las sedes') }}</option>
            @endif
            @foreach ($locations as $location)
                <option value="{{ $location->id }}" @selected($active === $location->id)>{{ $location->name }}</option>
            @endforeach
        </select>
    @elseif ($locations->count() === 1)
        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $locations->first()->name }}</span>
    @endif
</div>
