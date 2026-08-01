{{-- The shared help affordance (prompt 92): an unobtrusive icon on every panel page, opening a short
     static panel. Links to the glossary (the club's terms of art) and the per-screen guides. Content only —
     it never gates or changes anything, and it loads nothing. --}}
<div x-data="{ open: false }" class="relative" data-screen-help>
    <button
        type="button"
        @click="open = ! open"
        aria-haspopup="true"
        :aria-expanded="open.toString()"
        aria-label="{{ __('Ayuda') }}"
        class="flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-gray-200"
    >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z"/>
        </svg>
    </button>

    <div
        x-show="open"
        x-cloak
        @click.outside="open = false"
        @keydown.escape.window="open = false"
        class="absolute right-0 z-40 mt-1 w-72 rounded-xl border border-gray-200 bg-white p-2 shadow-lg dark:border-white/10 dark:bg-gray-900"
    >
        <p class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('Ayuda') }}</p>
        <a href="{{ \App\Filament\Pages\Glosario::getUrl() }}" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-gray-700 transition hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/5">
            <span>📖</span> {{ __('Glosario de términos del club') }}
        </a>
        <p class="px-3 pb-1 pt-2 text-xs text-gray-500 dark:text-gray-400">
            {{ __('Cada pantalla explica para qué sirve y qué puedes hacer. Consulta el glosario para los términos.') }}
        </p>
    </div>
</div>
