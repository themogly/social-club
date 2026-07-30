@php
    /** @var \App\Models\Minute $minute */
    $agenda = array_values(array_filter((array) $minute->agenda, 'is_string'));
    $resolutions = array_values(array_filter((array) $minute->resolutions, 'is_array'));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 28px 34px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 11px; line-height: 1.5; }
        .head { border-bottom: 2px solid #2563eb; padding-bottom: 8px; margin-bottom: 12px; }
        .org { font-size: 10px; color: #475569; font-weight: bold; letter-spacing: .04em; text-transform: uppercase; }
        h1 { font-size: 17px; color: #0f172a; margin: 4px 0 2px; }
        .meta { font-size: 10px; color: #475569; }
        h2 { font-size: 12px; color: #1d4ed8; border-bottom: 1px solid #e2e8f0; padding-bottom: 3px; margin: 16px 0 6px; }
        table.kv { width: 100%; border-collapse: collapse; margin: 6px 0 4px; }
        table.kv td { padding: 5px 8px; border-bottom: 1px solid #eef2f7; vertical-align: top; }
        table.kv td.k { width: 34%; color: #475569; }
        ol, ul { margin: 4px 0 4px 18px; padding: 0; }
        li { margin: 2px 0; }
        .votes { color: #475569; font-size: 10px; }
        .body { margin: 6px 0; white-space: pre-line; }
        .muted { color: #94a3b8; }
        .sign { width: 100%; margin-top: 44px; border-collapse: collapse; }
        .sign td { border: none; border-top: 1px solid #0f172a; padding-top: 6px; text-align: center; width: 50%; font-size: 10px; color: #475569; }
        .foot { margin-top: 22px; color: #94a3b8; font-size: 9px; }
    </style>
</head>
<body>
    <div class="head">
        <div class="org">{{ $orgName }}</div>
        <h1>{{ __('Acta nº :n', ['n' => $minute->number]) }} · {{ $bookLabel }}</h1>
        <div class="meta">
            {{ $typeLabel ?: __('Reunión') }} ·
            {{ __('Celebrada el :date', ['date' => optional($minute->held_on)->format('d/m/Y')]) }}
            @if ($minute->location){{ ' · '.$minute->location->name }}@else{{ ' · '.__('General (organización)') }}@endif
        </div>
    </div>

    <table class="kv">
        <tr>
            <td class="k">{{ __('Quórum (presente / requerido)') }}</td>
            <td>{{ $minute->quorum_present }} / {{ $minute->quorum_required }}</td>
        </tr>
        <tr>
            <td class="k">{{ __('Estado') }}</td>
            <td>{{ $minute->signed_at === null ? __('Borrador') : __('Firmada el :date', ['date' => $minute->signed_at->format('d/m/Y H:i')]) }}</td>
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

    <h2>{{ __('Acuerdos') }}</h2>
    @if ($resolutions === [])
        <p class="muted">{{ __('Sin acuerdos') }}</p>
    @else
        <ol>
            @foreach ($resolutions as $r)
                <li>
                    {{ data_get($r, 'texto') }}
                    <span class="votes">({{ __('A favor') }} {{ (int) data_get($r, 'favor', 0) }} · {{ __('En contra') }} {{ (int) data_get($r, 'contra', 0) }} · {{ __('Abstención') }} {{ (int) data_get($r, 'abstencion', 0) }})</span>
                </li>
            @endforeach
        </ol>
    @endif

    <h2>{{ __('Asistentes') }}</h2>
    @if (empty($attendeeNames))
        <p class="muted">{{ __('Sin asistentes registrados') }}</p>
    @else
        <ul>
            @foreach ($attendeeNames as $name)
                <li>{{ $name }}</li>
            @endforeach
        </ul>
    @endif

    @if ($minute->body)
        <h2>{{ __('Desarrollo de la sesión') }}</h2>
        <div class="body">{{ $minute->body }}</div>
    @endif

    <table class="sign">
        <tr>
            <td>{{ __('El/La Secretario/a') }}</td>
            <td>{{ __('El/La Presidente/a') }}</td>
        </tr>
    </table>

    <p class="foot">{{ __('Documento generado por la asociación como registro interno. No constituye asesoramiento legal.') }}</p>
</body>
</html>
