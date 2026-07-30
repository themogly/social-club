<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Registro de dispensación') }}</title>
    <style>
        @page { margin: 26px 30px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 9.5px; }
        .head { border-bottom: 2px solid #2563eb; padding-bottom: 8px; margin-bottom: 10px; }
        .org { font-size: 10px; color: #475569; font-weight: bold; letter-spacing: .04em; text-transform: uppercase; }
        h1 { font-size: 17px; margin: 4px 0 2px; color: #0f172a; }
        .meta { font-size: 10px; color: #475569; }
        .summary { width: 100%; margin: 8px 0 4px; border-collapse: collapse; }
        .summary td { border: 1px solid #e2e8f0; padding: 5px 7px; width: 33%; }
        .summary .label { font-size: 8px; text-transform: uppercase; letter-spacing: .04em; color: #475569; display: block; }
        .summary .value { font-size: 11px; font-weight: bold; color: #0f172a; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.data th { text-align: left; font-size: 8px; text-transform: uppercase; letter-spacing: .03em; color: #475569; border-bottom: 1px solid #cbd5e1; padding: 4px 6px; }
        table.data td { padding: 3px 6px; border-bottom: 1px solid #eef2f7; color: #0f172a; }
        table.data .num { text-align: right; }
        .empty { color: #94a3b8; font-style: italic; padding: 6px; }
        .foot { position: fixed; bottom: -10px; left: 0; right: 0; font-size: 8px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <div class="head">
        <div class="org">{{ $orgName }}</div>
        <h1>{{ __('Registro de dispensación') }}</h1>
        <div class="meta">{{ $scopeLabel }} · {{ $periodLabel }} · {{ __('Generado') }} {{ $generatedAt->format('d/m/Y H:i') }}</div>
    </div>

    @if (! empty($summary))
        <table class="summary">
            <tr>
                @foreach ($summary as $chip)
                    <td><span class="label">{{ $chip['label'] }}</span><span class="value">{{ $chip['value'] }}</span></td>
                @endforeach
            </tr>
        </table>
    @endif

    @if ($count === 0)
        <p class="empty">{{ __('Sin dispensaciones en este período') }}</p>
    @else
        <table class="data">
            <thead>
                <tr>
                    <th>{{ __('Fecha') }}</th>
                    <th>{{ __('Nº socio') }}</th>
                    <th>{{ __('Genética') }}</th>
                    <th>{{ __('Lote') }}</th>
                    <th class="num">{{ __('Gramos') }}</th>
                    <th class="num">{{ __('Aportación') }}</th>
                    <th>{{ __('Operador') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ \Illuminate\Support\Carbon::parse($row['fecha'])->format('d/m/Y H:i') }}</td>
                        <td>{{ $row['member_no'] }}</td>
                        <td>{{ $row['genetica'] }}</td>
                        <td>{{ $row['lote'] }}</td>
                        <td class="num">{{ $row['grams'] }}</td>
                        <td class="num">{{ $row['total'] }}</td>
                        <td>{{ $row['operador'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="foot">{{ $orgName }} · {{ __('Registro de dispensación') }} · {{ __('No constituye asesoramiento legal.') }}</p>
</body>
</html>
