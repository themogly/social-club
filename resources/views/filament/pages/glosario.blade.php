<x-filament-panels::page>
    {{-- Static + searchable (Alpine). No queries, nothing lazy to load — help never slows anything. --}}
    <div x-data="{ q: '' }" class="mx-auto w-full max-w-3xl">
        <input
            type="search"
            x-model="q"
            placeholder="{{ __('Buscar término…') }}"
            aria-label="{{ __('Buscar término…') }}"
            class="mb-5 h-11 w-full rounded-xl border border-gray-300 bg-white px-4 text-base text-gray-950 placeholder:text-gray-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/30 dark:border-white/10 dark:bg-white/5 dark:text-white"
        >

        <dl class="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white dark:divide-white/5 dark:border-white/10 dark:bg-gray-900">
            @foreach ($terms as $term => $definition)
                <div
                    data-glossary-term="{{ $term }}"
                    x-show="q === '' || '{{ Str::lower($term) }}'.includes(q.toLowerCase()) || {{ Js::from(Str::lower(__($definition))) }}.includes(q.toLowerCase())"
                    class="px-4 py-3"
                >
                    <dt class="font-semibold text-gray-950 dark:text-white">{{ $term }}</dt>
                    <dd class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __($definition) }}</dd>
                </div>
            @endforeach
        </dl>

        <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
            {{ __('Los términos técnicos se mantienen en español porque tienen valor legal. Nada de esto constituye asesoramiento legal.') }}
        </p>

        {{-- Per-screen guides: what each screen is for and the things you do there (help index). --}}
        <h2 class="mb-3 mt-8 text-base font-semibold text-gray-950 dark:text-white">{{ __('Guías por pantalla') }}</h2>
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($guides as $guide)
                <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __($guide['title']) }}</h3>
                    @foreach ($guide['body'] as $paragraph)
                        <p class="mt-1.5 text-sm text-gray-600 dark:text-gray-300">{{ __($paragraph) }}</p>
                    @endforeach
                </section>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
