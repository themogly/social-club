<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 26px 30px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 10px; }
        .head { border-bottom: 2px solid #2563eb; padding-bottom: 8px; margin-bottom: 12px; }
        .org { font-size: 11px; color: #475569; font-weight: bold; letter-spacing: .04em; text-transform: uppercase; }
        h1 { font-size: 18px; margin: 4px 0 2px; color: #0f172a; }
        .meta { font-size: 10px; color: #475569; }
        .summary { width: 100%; margin: 10px 0 4px; border-collapse: collapse; }
        .summary td { border: 1px solid #e2e8f0; padding: 6px 8px; width: 20%; vertical-align: top; }
        .summary .label { font-size: 8px; text-transform: uppercase; letter-spacing: .04em; color: #475569; display: block; }
        .summary .value { font-size: 12px; font-weight: bold; color: #0f172a; }
        h2 { font-size: 12px; margin: 16px 0 6px; color: #1d4ed8; border-bottom: 1px solid #e2e8f0; padding-bottom: 3px; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        table.data th { text-align: left; font-size: 8px; text-transform: uppercase; letter-spacing: .03em; color: #475569; border-bottom: 1px solid #cbd5e1; padding: 4px 6px; }
        table.data td { padding: 4px 6px; border-bottom: 1px solid #eef2f7; color: #0f172a; }
        table.data .num { text-align: right; }
        table.data tfoot td { border-top: 1.5px solid #cbd5e1; border-bottom: 0; font-weight: bold; background: #f8fafc; }
        .empty { color: #94a3b8; font-style: italic; padding: 6px; }
        .note { font-size: 8px; color: #94a3b8; font-style: italic; margin: 2px 0 0; }
        .foot { position: fixed; bottom: -10px; left: 0; right: 0; font-size: 8px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <div class="head">
        @include('documents.partials.identity')
        <h1>{{ $title }}</h1>
        <div class="meta">{{ $scopeLabel }} · {{ $periodLabel }} · {{ __('Generado') }} {{ $generatedAt->format('d/m/Y H:i') }}</div>
    </div>

    @if (! empty($summary))
        <table class="summary">
            <tr>
                @foreach ($summary as $chip)
                    <td>
                        <span class="label">{{ $chip['label'] }}</span>
                        <span class="value">{{ $chip['value'] }}</span>
                    </td>
                @endforeach
            </tr>
        </table>
    @endif

    @foreach ($tables as $table)
        <h2>{{ $table->title }}</h2>
        @if (! $table->hasRows())
            <p class="empty">{{ $table->empty ?? __('Sin datos en este período') }}</p>
        @else
            <table class="data">
                <thead>
                    <tr>
                        @foreach ($table->columns as $column)
                            <th @class(['num' => $column->numeric()])>{{ $column->label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($table->rows as $row)
                        <tr>
                            @foreach ($table->columns as $column)
                                <td @class(['num' => $column->numeric()])>{{ $column->display($row[$column->key] ?? null) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
                @if ($table->hasTotals())
                    <tfoot>
                        <tr>
                            @foreach ($table->columns as $i => $column)
                                <td @class(['num' => $column->numeric()])>
                                    @if ($i === 0){{ __('Total') }}@elseif (array_key_exists($column->key, $table->totals)){{ $column->display($table->totals[$column->key]) }}@endif
                                </td>
                            @endforeach
                        </tr>
                    </tfoot>
                @endif
            </table>
            @if ($table->note)<p class="note">{{ $table->note }}</p>@endif
        @endif
    @endforeach

    <div class="foot">{{ $orgName }} · {{ $title }}</div>
</body>
</html>
