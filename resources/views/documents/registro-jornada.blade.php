{{-- Prompt 281 — the monthly registro de jornada sheet, one page per person: what a gestor asks for. --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Registro de jornada') }} — {{ $month }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0f172a; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        .muted { color: #475569; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border-bottom: 1px solid #e2e8f0; padding: 4px 6px; text-align: left; }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        .sign { margin-top: 40px; border-top: 1px solid #0f172a; width: 60%; padding-top: 4px; }
    </style>
</head>
<body>
@foreach ($people as $person)
    <div class="page">
        <h1>{{ __('Registro de jornada') }} — {{ $month }}</h1>
        <p class="muted">{{ $identity['legal_name'] ?? $identity['display_name'] }}@if ($identity['tax_id']) · {{ $identity['tax_id'] }}@endif</p>
        <p><strong>{{ __('Persona') }}:</strong> {{ $person['name'] }}</p>
        <table>
            <thead><tr><th>{{ __('Fecha') }}</th><th>{{ __('Sede') }}</th><th>{{ __('Entrada') }}</th><th>{{ __('Salida') }}</th><th>{{ __('Total (min)') }}</th><th>{{ __('Avisos') }}</th></tr></thead>
            <tbody>
            @foreach ($person['rows'] as $line)
                <tr>@foreach (array_slice($line, 1) as $cell)<td>{{ $cell }}</td>@endforeach</tr>
            @endforeach
            </tbody>
        </table>
        <p><strong>{{ __('Total del mes: :total', ['total' => sprintf('%d h %02d min', intdiv($person['minutes'], 60), $person['minutes'] % 60)]) }}</strong></p>
        <p class="sign">{{ __('Firma de la persona trabajadora') }}</p>
        <p class="muted">{{ __('Generado el :date', ['date' => local_datetime($generatedAt, 'd/m/Y H:i')]) }}</p>
    </div>
@endforeach
</body>
</html>
