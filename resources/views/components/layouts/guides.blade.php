{{-- Prompt 353 — the minimal shell for /docs, a staff member's own phone: the club's name and colours, nothing else of
     the app. No service worker, no counter manifest; never indexed. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="robots" content="noindex, nofollow">
        <meta name="theme-color" content="#2563eb" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#0f172a" media="(prefers-color-scheme: dark)">
        <title>{{ $title ?? __('Guías') }} · {{ config('app.name') }}</title>
        {{-- No Livewire here, so Alpine comes from the socio entry (232's rule, AlpineShipsWhereItIsUsedTest); app.js first,
             for the one PIN pad's digits (window.pinEntry). --}}
        @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/socio.js'])
    </head>
    <body class="min-h-full bg-surface-alt text-ink antialiased dark:bg-slate-950 dark:text-slate-100">
        <header class="border-b border-line bg-surface dark:border-slate-800 dark:bg-slate-900">
            <div class="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-3">
                <a href="{{ route('guides.docs') }}" class="flex items-center gap-2 font-semibold text-ink dark:text-white">
                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-brand text-white" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25"/></svg>
                    </span>
                    <span>{{ config('app.name') }} · {{ __('Guías') }}</span>
                </a>
                {{ $actions ?? '' }}
            </div>
        </header>
        <main class="mx-auto w-full max-w-5xl px-4 py-6">
            {{ $slot }}
        </main>
    </body>
</html>
