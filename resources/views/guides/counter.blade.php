{{-- Prompt 353 — ⋯ → Guías on the counter: the index, or one guide, in the counter's own layout (top bar = the way back). --}}
<x-layouts.counter :title="$guide ? $guide['guide']->title : __('Guías')">
    <div class="mx-auto w-full max-w-5xl px-4 py-6" data-counter-guides>
        @if ($guide)
            <a href="{{ route('counter.guides') }}" data-way-back class="mb-4 inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-brand hover:underline dark:text-slate-200">← {{ __('Todas las guías') }}</a>
            @include('guides.partials.article', ['page' => $guide])
        @else
            <h1 class="text-2xl font-semibold text-ink dark:text-white">{{ __('Guías') }}</h1>
            <p class="mt-1 mb-5 text-sm text-ink-muted dark:text-slate-400">{{ __('Cómo se usa el mostrador, paso a paso. Siempre la versión actual.') }}</p>
            @include('guides.partials.index', ['link' => fn ($g) => route('counter.guides.show', $g->slug)])
        @endif
    </div>
</x-layouts.counter>
