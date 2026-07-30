@props(['title' => __('Área de socio/a')])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#2563eb">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    {{-- PWA manifest + icons are wired in the PWA-mechanics pass. --}}
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-full bg-[--surface-alt] text-[--text]" style="--surface-alt:#f8fafc;--text:#0f172a;">
    <main class="mx-auto w-full max-w-md p-4 pb-24">
        {{ $slot }}
    </main>
</body>
</html>
