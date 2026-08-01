<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('Sistema temporalmente no disponible') }}</title>
    <style>
        /* Self-contained: this page must render WITHOUT the cache, the auth stack or built assets (prompt 124). */
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #f8fafc; color: #0f172a; padding: 24px; }
        .card { max-width: 30rem; text-align: center; background: #fff; border: 1px solid #e2e8f0;
            border-radius: 16px; padding: 2rem 1.75rem; box-shadow: 0 8px 24px rgba(15,23,42,.06); }
        .icon { font-size: 2.5rem; line-height: 1; }
        h1 { font-size: 1.25rem; margin: 1rem 0 .5rem; }
        p { margin: 0; color: #475569; font-size: .95rem; line-height: 1.5; }
        @media (prefers-color-scheme: dark) {
            body { background: #0f172a; color: #f1f5f9; }
            .card { background: #1e293b; border-color: #334155; }
            p { color: #94a3b8; }
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon" aria-hidden="true">⚙️</div>
        <h1>{{ __('Sistema temporalmente no disponible') }}</h1>
        <p>{{ $message ?? __('El sistema no está disponible temporalmente (infraestructura degradada). Inténtalo de nuevo en unos momentos.') }}</p>
    </div>
</body>
</html>
