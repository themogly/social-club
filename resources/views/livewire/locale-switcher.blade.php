{{-- Topbar language toggle (prompts 19 + 143). A SEGMENTED PILL: a bounded gray track with the two
     locale segments inside it, so the pair reads as one control with a clear edge — not two loose
     letters that merge into the avatar initials beside them. The 16px column-gap of Filament's
     `.fi-topbar-end` separates this control from the help icon and the avatar (group gap); the segments
     sit tight inside the track (inner gap), so group gap >> inner gap. Matches the dashboard period
     toggle's blue-active convention. --}}
<div
    role="group"
    aria-label="{{ __('Idioma') }}"
    class="inline-flex items-center gap-0.5 rounded-lg bg-gray-100 p-0.5 dark:bg-white/5"
>
    @foreach ($locales as $locale)
        <button
            type="button"
            wire:click="switchLocale('{{ $locale }}')"
            aria-pressed="{{ $current === $locale ? 'true' : 'false' }}"
            @class([
                // >= 24x24 CSS px target (WCAG 2.2 Target Size, prompt 98); focus-visible ring for keyboard.
                // The active fill is the Filament panel primary via its CSS var (there is no `bg-primary-*`
                // utility in this build, and the var honours a per-location accent override — prompt 03).
                'inline-flex min-h-[1.5rem] min-w-[1.75rem] items-center justify-center rounded-md px-2 py-1 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--primary-600)]',
                'bg-[var(--primary-600)] text-white shadow-sm' => $current === $locale,
                'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white' => $current !== $locale,
            ])
        >
            {{ strtoupper($locale) }}
        </button>
    @endforeach
</div>
