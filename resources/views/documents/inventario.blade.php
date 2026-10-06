{{-- Prompt 318 — the Inventario report: every line, who counted it and when, the differences, the reasons and the totals. --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Inventario') }} — {{ $take->location?->name }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #0f172a; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        h2 { font-size: 12px; margin: 16px 0 0; }
        .muted { color: #475569; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border-bottom: 1px solid #e2e8f0; padding: 3px 5px; text-align: left; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .totals td { border: 0; padding: 2px 8px 2px 0; }
    </style>
</head>
<body>
    <h1>{{ __('Inventario') }} — {{ $take->location?->name }}</h1>
    <p class="muted">{{ $identity['legal_name'] ?? $identity['display_name'] }}@if ($identity['tax_id']) · {{ $identity['tax_id'] }}@endif</p>
    <p>
        <strong>{{ __('Estado') }}:</strong> {{ $take->status->label() }} ·
        {{ __('Abierto el :date por :name', ['date' => local_datetime($take->opened_at, 'd/m/Y H:i', $take->location), 'name' => (string) $take->openedBy?->name]) }}
        @if ($take->committed_at) · {{ __('Aplicado el :date por :name', ['date' => local_datetime($take->committed_at, 'd/m/Y H:i', $take->location), 'name' => (string) $take->committedBy?->name]) }}@endif
    </p>

    <table class="totals" data-count-totals>
        <tr>
            <td><strong>{{ __('Líneas') }}:</strong> {{ $totals['lines'] }}</td>
            <td><strong>{{ __('No contado') }}:</strong> {{ $totals['not_counted'] }}</td>
            <td><strong>{{ __('Con diferencia') }}:</strong> {{ $totals['differences'] }}</td>
            <td><strong>{{ __('Diferencia neta (bote)') }}:</strong> {{ $totals['net_weight'] }} · {{ $totals['net_units_text'] }}</td>
            <td><strong>{{ __('Diferencia neta (reserva sellada)') }}:</strong> {{ $totals['net_reserve_weight'] }}</td>
            <td><strong>{{ __('Valor (a la aportación)') }}:</strong> {{ $totals['net_value'] }}</td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th rowspan="2">{{ __('Lote o producto') }}</th><th rowspan="2">{{ __('Tipo') }}</th>
                <th class="num" colspan="3">{{ __('El bote') }}</th><th class="num" colspan="3">{{ __('La reserva sellada') }}</th>
                <th class="num" rowspan="2">{{ __('Valor') }}</th><th rowspan="2">{{ __('Motivo') }}</th><th rowspan="2">{{ __('Contado por') }}</th>
            </tr>
            <tr>
                <th class="num">{{ __('Sistema') }}</th><th class="num">{{ __('Contado') }}</th><th class="num">{{ __('Diferencia') }}</th>
                <th class="num">{{ __('Sistema') }}</th><th class="num">{{ __('Contado') }}</th><th class="num">{{ __('Diferencia') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach (array_filter($rows, fn (array $row): bool => ! ($row['optional'] && ! $row['settled'])) as $row)
                <tr>
                    <td>{{ $row['name'] }}@if ($row['reference']) · {{ $row['reference'] }}@endif</td>
                    <td>{{ $row['group'] }}</td>
                    <td class="num">{{ $row['expected'] ?? '—' }}</td>
                    <td class="num">{{ $row['not_counted'] ? __('No contado') : ($row['counted'] ?? '—') }}</td>
                    <td class="num">{{ $row['difference_text'] }}</td>
                    <td class="num">{{ $row['counts_reserve'] ? ($row['expected_reserve'] ?? '—') : '' }}</td>
                    <td class="num">{{ $row['counts_reserve'] && ! $row['not_counted'] ? ($row['counted_reserve'] ?? '—') : '' }}</td>
                    <td class="num">{{ $row['counts_reserve'] ? $row['reserve_difference_text'] : '' }}</td>
                    <td class="num">{{ $row['value_text'] }}</td>
                    <td>{{ $row['not_counted'] ? $row['not_counted_reason'] : trim(($row['reason_label'] ?? '').($row['note'] ? ': '.$row['note'] : ''), ': ') }}</td>
                    <td>{{ $row['counted_by'] }}@if ($row['counted_at']) · {{ $row['counted_at'] }}@endif</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th colspan="4">{{ __('Total') }}</th><th class="num">{{ $totals['net_weight'] }}</th>
                <th colspan="2"></th><th class="num">{{ $totals['net_reserve_weight'] }}</th>
                <th class="num">{{ $totals['net_value'] }}</th><th colspan="2"></th>
            </tr>
        </tfoot>
    </table>

    <p class="muted">{{ __('La diferencia de cada línea es contra lo que había en el sistema cuando se contó. El valor es a la aportación del lote o producto.') }}</p>
    <p class="muted">{{ __('Generado el :date', ['date' => local_datetime($generatedAt, 'd/m/Y H:i')]) }}</p>
</body>
</html>
