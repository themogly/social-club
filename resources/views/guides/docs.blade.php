{{-- Prompt 353 — /docs once the PIN is in: the reader's guides, or one guide. Guides only — nothing here links into the
     counter or the panel. --}}
<x-layouts.guides :title="$guide ? $guide['guide']->title : __('Guías')">
    <x-slot:actions>
        <form method="POST" action="{{ route('guides.docs.signout') }}">
            @csrf
            <button type="submit" data-docs-signout class="inline-flex min-h-11 items-center rounded-xl px-3 text-sm font-semibold text-ink-muted hover:bg-surface-alt dark:text-slate-300 dark:hover:bg-slate-800">{{ __('Salir') }}</button>
        </form>
    </x-slot:actions>
    @if ($guide)
        <a href="{{ route('guides.docs') }}" data-way-back class="mb-4 inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-brand hover:underline dark:text-slate-200">← {{ __('Todas las guías') }}</a>
        @include('guides.partials.article', ['page' => $guide])
    @else
        <h1 class="text-2xl font-semibold text-ink dark:text-white">{{ __('Hola, :name', ['name' => $reader->name]) }}</h1>
        <p class="mt-1 mb-5 text-sm text-ink-muted dark:text-slate-400">{{ __('Las guías del club, siempre la versión actual.') }}</p>
        @include('guides.partials.index', ['link' => fn ($g) => route('guides.docs.show', $g->slug)])
    @endif
</x-layouts.guides>
