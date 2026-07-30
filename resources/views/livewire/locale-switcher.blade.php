<div class="flex items-center gap-1 px-2">
    @foreach ($locales as $locale)
        <button
            type="button"
            wire:click="switchLocale('{{ $locale }}')"
            @class([
                'rounded-md px-2 py-1 text-xs font-semibold transition',
                'bg-primary-600 text-white' => $current === $locale,
                'text-gray-500 hover:text-gray-950 dark:text-gray-400 dark:hover:text-white' => $current !== $locale,
            ])
        >
            {{ strtoupper($locale) }}
        </button>
    @endforeach
</div>
