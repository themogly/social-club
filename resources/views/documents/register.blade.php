<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Libro de socios') }}</title>
    <style>
        @page { margin: 26px 30px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 10px; }
        .head { border-bottom: 2px solid #2563eb; padding-bottom: 8px; margin-bottom: 12px; }
        .org { font-size: 10px; color: #475569; font-weight: bold; letter-spacing: .04em; text-transform: uppercase; }
        h1 { font-size: 18px; margin: 4px 0 2px; color: #0f172a; }
        .meta { font-size: 10px; color: #475569; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.data th { text-align: left; font-size: 8px; text-transform: uppercase; letter-spacing: .03em; color: #475569; border-bottom: 1px solid #cbd5e1; padding: 4px 6px; }
        table.data td { padding: 4px 6px; border-bottom: 1px solid #eef2f7; color: #0f172a; }
        .empty { color: #94a3b8; font-style: italic; padding: 6px; }
        .count { margin-top: 8px; font-size: 10px; color: #475569; font-weight: bold; }
        .foot { position: fixed; bottom: -10px; left: 0; right: 0; font-size: 8px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <div class="head">
        <div class="org">{{ $orgName }}</div>
        <h1>{{ __('Libro de socios') }}</h1>
        <div class="meta">
            {{ $sedeLabel }} ·
            {{ __('a fecha de :date', ['date' => \Illuminate\Support\Carbon::parse($asAt)->format('d/m/Y')]) }} ·
            {{ __('Generado') }} {{ $generatedAt->format('d/m/Y H:i') }}
        </div>
    </div>

    @if ($count === 0)
        <p class="empty">{{ __('Sin socios a esta fecha') }}</p>
    @else
        <table class="data">
            <thead>
                <tr>
                    <th>{{ __('Nº socio') }}</th>
                    <th>{{ __('Nombre') }}</th>
                    <th>{{ __('Documento') }}</th>
                    <th>{{ __('Alta') }}</th>
                    <th>{{ __('Baja') }}</th>
                    <th>{{ __('Estado') }}</th>
                    <th>{{ __('Sede') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row['member_no'] ?? '—' }}</td>
                        <td>{{ $row['nombre'] ?? '—' }}</td>
                        <td>{{ $row['documento'] ?? '—' }}</td>
                        <td>{{ $row['alta'] ?? '—' }}</td>
                        <td>{{ $row['baja'] ?? '—' }}</td>
                        <td>{{ $row['estado'] ?? '—' }}</td>
                        <td>{{ $row['sede'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="count">{{ trans_choice(':count socio en el libro|:count socios en el libro', $count, ['count' => $count]) }}</p>
    @endif

    <p class="foot">{{ __('Documento generado por la asociación como registro interno. No constituye asesoramiento legal.') }}</p>
</body>
</html>
