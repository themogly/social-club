<x-layouts.socio :title="__('Avisos')">
    <header class="mb-4 flex items-center justify-between">
        <h1 class="text-xl font-semibold">{{ __('Avisos') }}</h1>
        <a href="{{ route('socio.events') }}" class="text-sm font-medium text-brand dark:text-slate-100">{{ __('Eventos') }} &rarr;</a>
    </header>

    @forelse ($announcements as $a)
        <article class="mt-3 rounded-2xl border border-line bg-surface p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-start justify-between gap-3">
                <h2 class="font-semibold leading-snug">{{ $a->title }}</h2>
                @if ($a->location)
                    <span class="shrink-0 rounded-full bg-brand-tint px-2 py-0.5 text-[11px] font-medium text-brand dark:bg-slate-800 dark:text-slate-300">{{ $a->location->name }}</span>
                @endif
            </div>
            @if ($a->body)
                <p class="mt-1.5 whitespace-pre-line text-sm text-ink-muted dark:text-slate-300">{{ $a->body }}</p>
            @endif
            <p class="mt-2 text-xs text-ink-muted dark:text-slate-400">{{ optional($a->published_at)->format('d/m/Y H:i') }}</p>
        </article>
    @empty
        <div class="mt-6 rounded-2xl border border-dashed border-line bg-surface p-8 text-center dark:border-slate-800 dark:bg-slate-900">
            <p class="text-sm text-ink-muted dark:text-slate-400">{{ __('No hay avisos por ahora.') }}</p>
        </div>
    @endforelse
</x-layouts.socio>
