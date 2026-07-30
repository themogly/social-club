{{-- Printable bar / merch ticket — worded as a NORMAL SALE (venta / ticket / importe /
     total), DELIBERATELY NOT the cannabis contribution vocabulary (aportación /
     contribución / dispensación). The bar is a separate ledger and this genuinely is a
     sale of refreshments / merch. A self-contained print page: no counter layout, inline
     styles so it prints reliably. Reached only via an authorization-checked, ULID route. --}}
@php
    use App\Support\Money;
    $isVoided = $order->status === \App\Enums\OrderStatus::VOIDED;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('Ticket de venta') }} · {{ $order->organisation?->name ?? config('app.name') }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            margin: 0; background: #f8fafc; color: #0f172a; padding: 24px;
        }
        .ticket { max-width: 380px; margin: 0 auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; }
        .center { text-align: center; }
        .muted { color: #475569; }
        h1 { font-size: 18px; margin: 0; }
        h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .05em; color: #475569; margin: 20px 0 8px; }
        .sub { font-size: 12px; margin: 2px 0 0; }
        hr { border: none; border-top: 1px dashed #e2e8f0; margin: 16px 0; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { text-align: left; font-weight: 600; color: #475569; padding-bottom: 6px; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
        td { padding: 4px 0; vertical-align: top; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .ref { font-size: 11px; color: #475569; font-style: italic; }
        .row { display: flex; justify-content: space-between; font-size: 13px; padding: 3px 0; }
        .total { display: flex; justify-content: space-between; font-size: 16px; font-weight: 700; border-top: 2px solid #0f172a; margin-top: 8px; padding-top: 8px; }
        .foot { font-size: 11px; color: #475569; margin-top: 20px; line-height: 1.5; }
        .badge { display: inline-block; background: #dc2626; color: #fff; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; padding: 4px 10px; border-radius: 999px; margin-bottom: 12px; }
        .actions { max-width: 380px; margin: 16px auto 0; text-align: center; }
        button {
            font: inherit; font-weight: 600; cursor: pointer; background: #2563eb; color: #fff;
            border: none; border-radius: 10px; padding: 12px 24px;
        }
        @media print {
            body { background: #fff; padding: 0; }
            .ticket { border: none; max-width: none; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
    <div class="ticket">
        @if ($isVoided)
            <div class="center"><span class="badge">{{ __('Anulada') }}</span></div>
        @endif

        <div class="center">
            <h1>{{ $order->organisation?->name ?? config('app.name') }}</h1>
            <p class="sub muted">{{ $order->location?->name }}</p>
            @if ($order->location?->address)
                <p class="sub muted">{{ $order->location->address }}</p>
            @endif
            <p class="sub muted">{{ __('Ticket de venta') }}</p>
        </div>

        <hr>

        @if ($order->member)
            <div class="row"><span class="muted">{{ __('Socio') }}</span><span>{{ $order->member->fullName() }}</span></div>
            <div class="row"><span class="muted">{{ __('Nº de socio') }}</span><span>{{ $order->member->member_no }}</span></div>
        @endif
        <div class="row"><span class="muted">{{ __('Fecha') }}</span><span>{{ $order->created_at?->format('d/m/Y H:i') }}</span></div>
        <div class="row"><span class="muted">{{ __('Ticket nº') }}</span><span>{{ $order->id }}</span></div>
        @if ($order->reference)
            <div class="row"><span class="muted">{{ __('Referencia') }}</span><span>{{ $order->reference }}</span></div>
        @endif

        <h2>{{ __('Detalle de la venta') }}</h2>
        <table>
            <thead>
                <tr>
                    <th>{{ __('Concepto') }}</th>
                    <th class="num">{{ __('Cant.') }}</th>
                    <th class="num">{{ __('Precio') }}</th>
                    <th class="num">{{ __('Importe') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td>
                            {{ data_get($item, 'name') }}
                            @php $itemRef = data_get($item, 'reference'); @endphp
                            @if ($itemRef)<div class="ref">{{ $itemRef }}</div>@endif
                        </td>
                        <td class="num">{{ (int) data_get($item, 'qty', 0) }}</td>
                        <td class="num">{{ Money::fromCents((int) data_get($item, 'unit_price_cents', 0))->formatted() }}</td>
                        <td class="num">{{ Money::fromCents((int) data_get($item, 'line_total_cents', 0))->formatted() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="total">
            <span>{{ __('Total') }}</span>
            <span>{{ $order->total_cents->formatted() }}</span>
        </div>

        <h2>{{ __('Forma de pago') }}</h2>
        <div class="row"><span class="muted">{{ __('Efectivo') }}</span><span>{{ $order->cash_cents->formatted() }}</span></div>
        <div class="row"><span class="muted">{{ __('Monedero') }}</span><span>{{ $order->wallet_cents->formatted() }}</span></div>

        <p class="foot">
            {{ __('Ticket de venta de barra / tienda. Ingresos auxiliares del club, registrados en un libro contable independiente.') }}
        </p>
    </div>

    <div class="actions">
        <button type="button" onclick="window.print()">{{ __('Imprimir') }}</button>
    </div>
</body>
</html>
