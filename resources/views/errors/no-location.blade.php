<!DOCTYPE html>
{{-- Prompt 310 — a panel request from someone with no sede to work at lands here (EnsureActiveLocation), for a page load or
     a button press on a page already open, instead of a bare 403. The way back is the counter; the other way out is
     signing out. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('Sin sede asignada') }}</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
               font-family: Inter, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
               background:#f8fafc; color:#0f172a; }
        .card { max-width:420px; padding:2.5rem 2rem; text-align:center; }
        h1 { font-size:1.125rem; font-weight:600; margin:0 0 .5rem; }
        p { margin:0 0 1.5rem; color:#475569; font-size:.95rem; line-height:1.5; }
        .actions { display:flex; flex-direction:column; gap:.75rem; align-items:center; }
        a.btn, button.btn { display:inline-flex; align-items:center; justify-content:center; min-height:44px; padding:0 1.25rem;
               border-radius:.75rem; font:inherit; font-weight:600; font-size:.95rem; cursor:pointer; text-decoration:none; }
        a.btn { background:#2563eb; color:#ffffff; border:0; }
        a.btn:hover { background:#1d4ed8; }
        button.btn { background:transparent; color:#475569; border:1px solid #e2e8f0; }
        @media (prefers-color-scheme: dark) { body { background:#0f172a; color:#f8fafc; } p { color:#e2e8f0; } button.btn { color:#e2e8f0; border-color:#475569; } }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ __('Sin sede asignada') }}</h1>
        <p>{{ __('No tienes ninguna sede asignada. Pide a un responsable que te asigne una.') }}</p>
        <div class="actions">
            <a class="btn" href="{{ route('counter.home') }}">← {{ __('Volver al mostrador') }}</a>
            <form method="POST" action="{{ route('filament.admin.auth.logout') }}">
                @csrf
                <button type="submit" class="btn">{{ __('Cerrar sesión') }}</button>
            </form>
        </div>
    </div>
</body>
</html>
