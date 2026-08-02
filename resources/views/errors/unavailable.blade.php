<!DOCTYPE html>
{{-- Prompt 121: the ORDINARY-looking face of a panic lockdown. Deliberately mundane — a generic "try again
     shortly" that reads as a routine outage, never a "site locked" banner that would tell whoever is in the room
     they have been thwarted. No branding beyond the wordmark, no status, no hint that anything was triggered. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('Servicio no disponible') }}</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
               font-family: Inter, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
               background:#f8fafc; color:#0f172a; }
        .card { max-width:420px; padding:2.5rem 2rem; text-align:center; }
        h1 { font-size:1.125rem; font-weight:600; margin:0 0 .5rem; }
        p { margin:0; color:#475569; font-size:.95rem; line-height:1.5; }
        @media (prefers-color-scheme: dark) { body { background:#020617; color:#e2e8f0; } p { color:#94a3b8; } }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ __('Servicio no disponible temporalmente') }}</h1>
        <p>{{ __('Estamos realizando tareas de mantenimiento. Vuelve a intentarlo en unos minutos.') }}</p>
    </div>
</body>
</html>
