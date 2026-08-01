@php
    $agenda = array_values(array_filter(array_map(fn ($p) => trim((string) $p), (array) $convocatoria->agenda), fn ($p) => $p !== ''));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Convocatoria de asamblea') }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #0f172a; font-size: 12px; margin: 32px; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        h2 { font-size: 13px; margin: 20px 0 6px; border-bottom: 1px solid #e2e8f0; padding-bottom: 3px; }
        .sub { color: #475569; text-transform: uppercase; letter-spacing: .04em; font-size: 10px; margin: 0 0 14px; }
        .kv { width: 100%; border-collapse: collapse; margin: 8px 0; }
        .kv td { padding: 4px 0; vertical-align: top; }
        .kv .k { color: #475569; width: 34%; }
        .muted { color: #94a3b8; }
        .foot { margin-top: 28px; color: #94a3b8; font-size: 9px; }
    </style>
</head>
<body>
    <p class="sub">{{ __('Convocatoria de asamblea :type', ['type' => $convocatoria->type->label()]) }}</p>
    <h1>{{ $convocatoria->title }}</h1>
    <p class="muted">{{ $orgName }}</p>

    <table class="kv">
        <tr>
            <td class="k">{{ __('Fecha y hora') }}</td>
            <td>{{ $convocatoria->held_at->format('d/m/Y H:i') }}</td>
        </tr>
        @if ($convocatoria->second_call_at)
            <tr>
                <td class="k">{{ __('Segunda convocatoria') }}</td>
                <td>{{ $convocatoria->second_call_at->format('d/m/Y H:i') }}</td>
            </tr>
        @endif
        @if ($convocatoria->venue)
            <tr>
                <td class="k">{{ __('Lugar') }}</td>
                <td>{{ $convocatoria->venue }}</td>
            </tr>
        @endif
        @if ($convocatoria->quorum_required !== null)
            <tr>
                <td class="k">{{ __('Quórum requerido (primera convocatoria)') }}</td>
                <td>{{ $convocatoria->quorum_required }}</td>
            </tr>
        @endif
        <tr>
            <td class="k">{{ __('Estado') }}</td>
            <td>{{ $convocatoria->isIssued() ? __('Emitida el :date', ['date' => $convocatoria->issued_at->format('d/m/Y H:i')]) : __('Borrador') }}</td>
        </tr>
    </table>

    <h2>{{ __('Orden del día') }}</h2>
    @if ($agenda === [])
        <p class="muted">{{ __('Sin puntos') }}</p>
    @else
        <ol>
            @foreach ($agenda as $punto)
                <li>{{ $punto }}</li>
            @endforeach
        </ol>
    @endif

    @if ($convocatoria->body)
        <h2>{{ __('Texto de la convocatoria') }}</h2>
        <div>{{ $convocatoria->body }}</div>
    @endif

    <p class="foot">{{ __('Comunicación interna de la asociación dirigida a sus socios. No constituye asesoramiento legal.') }}</p>
</body>
</html>
