@props([
    'title' => __('Área de socio/a'),
    'nav' => true,
])
@php($authed = auth('member')->check())
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Brand chrome + PWA installability (iOS + Android). --}}
    <meta name="theme-color" content="#2563eb" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0f172a" media="(prefers-color-scheme: dark)">
    <meta name="color-scheme" content="light dark">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" type="image/png" sizes="32x32" href="/socio-icons/favicon-32.png">
    <link rel="apple-touch-icon" href="/socio-icons/apple-touch-icon-180.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="{{ __('Socio/a') }}">

    <title>{{ $title }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-full bg-surface-alt text-ink antialiased dark:bg-slate-950 dark:text-slate-100">
    @if ($authed && $nav)
        <header class="sticky top-0 z-20 border-b border-line/80 bg-surface/85 backdrop-blur dark:border-slate-800 dark:bg-slate-950/85">
            <div class="mx-auto flex w-full max-w-md items-center justify-between px-4 py-3">
                <a href="{{ route('socio.home') }}" class="flex items-center gap-2 font-semibold">
                    <img src="/socio-icons/favicon-32.png" width="24" height="24" alt="" class="rounded-md">
                    <span>{{ config('app.name') }}</span>
                </a>
                <div class="flex items-center gap-1.5">
                    {{-- Language switcher (prompt 96): persistent on every screen, so a member who lands in
                         the wrong language can escape it immediately. Reuses the admin switcher's pattern —
                         persists to the member row AND drops a session override for the very next request. --}}
                    @php($localeOptions = array_values(array_intersect(['es', 'en'], (array) \App\Support\Settings::get('enabled_locales', ['es', 'en']))))
                    @if (count($localeOptions) > 1)
                        <form method="POST" action="{{ route('socio.locale') }}" data-locale-switcher class="flex items-center rounded-lg border border-line p-0.5 dark:border-slate-800">
                            @csrf
                            @foreach ($localeOptions as $loc)
                                <button type="submit" name="locale" value="{{ $loc }}" data-locale="{{ $loc }}"
                                        @class([
                                            'rounded-md px-2 py-1 text-xs font-semibold uppercase transition',
                                            'bg-brand text-white' => app()->getLocale() === $loc,
                                            'text-ink-muted hover:text-brand dark:text-slate-400' => app()->getLocale() !== $loc,
                                        ])>{{ $loc }}</button>
                            @endforeach
                        </form>
                    @endif
                    <a href="{{ route('socio.notifications') }}"
                       aria-label="{{ __('Ajustes de avisos') }}"
                       class="rounded-lg p-2 text-ink-muted hover:bg-brand-tint hover:text-brand dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white {{ request()->routeIs('socio.notifications') ? 'text-brand dark:text-white' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.85 23.85 0 0 0 5.454-1.31A8.97 8.97 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.97 8.97 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m6.714 0a24.25 24.25 0 0 1-6.714 0m6.714 0a3 3 0 1 1-6.714 0"/>
                        </svg>
                    </a>
                </div>
            </div>
        </header>
    @endif

    <main class="mx-auto w-full max-w-md px-4 pt-4 {{ $authed && $nav ? 'pb-28' : 'pb-8' }}">
        {{ $slot }}
    </main>

    @if ($authed && $nav)
        @php($item = fn (string $route, bool $on) => $on
            ? 'text-brand dark:text-white'
            : 'text-ink-muted dark:text-slate-400')
        <nav class="fixed inset-x-0 bottom-0 z-20 border-t border-line bg-surface/95 backdrop-blur dark:border-slate-800 dark:bg-slate-950/95"
             style="padding-bottom: env(safe-area-inset-bottom);"
             aria-label="{{ __('Navegación de socio/a') }}">
            <div class="mx-auto grid w-full max-w-md grid-cols-4">
                <a href="{{ route('socio.home') }}" class="flex flex-col items-center gap-1 py-2.5 text-xs font-medium {{ $item('socio.home', request()->routeIs('socio.home')) }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-6 w-6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12 12 2.25 21.75 12M4.5 9.75v9.75a.75.75 0 0 0 .75.75H9.75V15.75a1.5 1.5 0 0 1 1.5-1.5h1.5a1.5 1.5 0 0 1 1.5 1.5v4.5h4.5a.75.75 0 0 0 .75-.75V9.75"/></svg>
                    {{ __('Inicio') }}
                </a>
                <a href="{{ route('socio.menu') }}" class="flex flex-col items-center gap-1 py-2.5 text-xs font-medium {{ $item('socio.menu', request()->routeIs('socio.menu')) }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-6 w-6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
                    {{ __('Menú') }}
                </a>
                <a href="{{ route('socio.history') }}" class="flex flex-col items-center gap-1 py-2.5 text-xs font-medium {{ $item('socio.history', request()->routeIs('socio.history')) }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-6 w-6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                    {{ __('Historial') }}
                </a>
                <a href="{{ route('socio.announcements') }}" class="relative flex flex-col items-center gap-1 py-2.5 text-xs font-medium {{ $item('socio.announcements', request()->routeIs('socio.announcements') || request()->routeIs('socio.events')) }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-6 w-6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.85 23.85 0 0 0 5.454-1.31A8.97 8.97 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.97 8.97 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m6.714 0a24.25 24.25 0 0 1-6.714 0m6.714 0a3 3 0 1 1-6.714 0"/></svg>
                    {{ __('Avisos') }}
                </a>
            </div>
        </nav>
    @endif

    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('/sw.js').catch(function () {});
            });
        }
    </script>
    {{ $footer ?? '' }}
</body>
</html>
