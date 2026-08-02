<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $ok ? __('Acceso reactivado') : __('Enlace no válido') }}</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
               font-family: Inter, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
               background:#f8fafc; color:#0f172a; }
        .card { max-width:440px; padding:2.5rem 2rem; text-align:center; }
        h1 { font-size:1.125rem; font-weight:600; margin:0 0 .5rem; }
        p { margin:0; color:#475569; font-size:.95rem; line-height:1.5; }
        @media (prefers-color-scheme: dark) { body { background:#020617; color:#e2e8f0; } p { color:#94a3b8; } }
    </style>
</head>
<body>
    <div class="card">
        @if ($ok)
            <h1>{{ __('Acceso reactivado') }}</h1>
            <p>{{ __('El club vuelve a estar operativo. Ya puedes iniciar sesión con normalidad.') }}</p>
        @else
            <h1>{{ __('Enlace no válido') }}</h1>
            <p>{{ __('Este enlace ha caducado o ya se ha utilizado. Solicita uno nuevo o espera al plazo de seguridad.') }}</p>
        @endif
    </div>
</body>
</html>
