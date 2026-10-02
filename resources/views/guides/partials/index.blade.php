{{-- Prompt 353 — the guides this reader may see, as cards. `$guides` is already filtered (GuideLibrary::visibleTo);
     `$link` builds each guide's URL in the current context (counter, phone or panel). --}}
<ul data-guide-index class="grid gap-3 sm:grid-cols-2">
    @foreach ($guides as $g)
        <li>
            <a href="{{ $link($g) }}" data-guide-link="{{ $g->slug }}"
               class="flex h-full flex-col rounded-xl border border-line bg-surface p-4 shadow-sm transition hover:border-brand hover:bg-brand-tint/40 dark:border-slate-700 dark:bg-slate-900 dark:hover:bg-slate-800">
                <span class="font-semibold text-ink dark:text-white">{{ $g->title }}</span>
                <span class="mt-1 text-sm text-ink-muted dark:text-slate-400">{{ $g->summary }}</span>
                <span class="mt-3 text-xs text-ink-muted dark:text-slate-400">
                    {{ __('Actualizada el :date', ['date' => $g->updated->locale(app()->getLocale())->isoFormat('LL')]) }}
                    @if ($g->forManagers()) · <span class="font-medium text-brand dark:text-slate-200">{{ __('Para responsables') }}</span>@endif
                </span>
            </a>
        </li>
    @endforeach
</ul>
