{{-- Reusable counter shell (check-in / dispensary / bar POS). Tablet-first, dark-mode
     aware, large touch targets — legible one-handed at 390px, comfortable at 1024px+.
     Pure presentation: no queries live here. Uses the app's own Tailwind via @vite. --}}
<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    class="h-full"
>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        {{-- No public/indexable surface anywhere in this app (NOTES §A / §B). --}}
        <meta name="robots" content="noindex, nofollow">

        <title>{{ $title ?? __('Contador') }} · {{ config('app.name') }}</title>

        {{-- Assets only when built (or the Vite dev server is hot); guarded so a
             full-page GET never 500s before `npm run build`, and tests stay quiet. --}}
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif

        @livewireStyles
    </head>
    <body class="min-h-full bg-surface-alt text-ink antialiased dark:bg-slate-950 dark:text-slate-100">
        <div class="mx-auto flex min-h-screen w-full max-w-6xl flex-col">
            {{-- Slim brand bar — generic across every counter terminal. --}}
            <header class="flex items-center justify-between border-b border-line px-4 py-3 dark:border-slate-800 sm:px-6">
                <div class="flex items-center gap-3">
                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-brand text-base font-bold text-white">
                        {{ mb_substr(config('app.name', 'C'), 0, 1) }}
                    </span>
                    <div class="leading-tight">
                        <p class="text-sm font-semibold">{{ config('app.name') }}</p>
                        <p class="text-xs text-ink-muted dark:text-slate-400">{{ $title ?? __('Contador') }}</p>
                    </div>
                </div>

                <a
                    href="{{ url('/') }}"
                    wire:navigate.ignore
                    class="rounded-lg px-4 py-2 text-sm font-medium text-ink-muted transition hover:bg-black/5 dark:text-slate-400 dark:hover:bg-white/5"
                >
                    {{ __('Salir') }}
                </a>
            </header>

            <main class="flex-1 px-4 py-5 sm:px-6">
                {{ $slot }}
            </main>
        </div>

        @livewireScripts
    </body>
</html>
