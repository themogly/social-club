{{-- Printable dispensation ticket — worded as a shared-cost CONTRIBUTION
     (aportación / contribución), never venta / cliente / precio de venta. A
     self-contained print page: no counter layout, inline styles so it prints
     reliably. Reached only via an authorization-checked, ULID route. --}}
@php
    use App\Support\Money;
    $isVoided = $dispensation->status === \App\Enums\DispensationStatus::VOIDED;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('Comprobante de aportación') }} · {{ $dispensation->organisation?->name ?? config('app.name') }}</title>
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
            <h1>{{ $dispensation->organisation?->name ?? config('app.name') }}</h1>
            <p class="sub muted">{{ $dispensation->location?->name }}</p>
            @if ($dispensation->location?->address)
                <p class="sub muted">{{ $dispensation->location->address }}</p>
            @endif
            <p class="sub muted">{{ __('Comprobante de aportación') }}</p>
        </div>

        <hr>

        <div class="row"><span class="muted">{{ __('Socio') }}</span><span>{{ $dispensation->member?->fullName() }}</span></div>
        <div class="row"><span class="muted">{{ __('Nº de socio') }}</span><span>{{ $dispensation->member?->member_no }}</span></div>
        <div class="row"><span class="muted">{{ __('Fecha') }}</span><span>{{ $dispensation->dispensed_at?->format('d/m/Y H:i') }}</span></div>
        <div class="row"><span class="muted">{{ __('Referencia') }}</span><span>{{ $dispensation->id }}</span></div>

        <h2>{{ __('Dispensación') }}</h2>
        <table>
            <thead>
                <tr>
                    <th>{{ __('Genética') }}</th>
                    <th class="num">{{ __('Peso') }}</th>
                    <th class="num">{{ __('€/g') }}</th>
                    <th class="num">{{ __('Aportación') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($dispensation->lines as $line)
                    <tr>
                        <td>{{ $line->genetic_name_snapshot }}</td>
                        <td class="num">{{ $line->grams_cg->formatted() }}</td>
                        <td class="num">{{ Money::fromCents($line->price_per_gram_cents)->formatted() }}</td>
                        <td class="num">{{ $line->line_total_cents->formatted() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="total">
            <span>{{ __('Total aportación') }}</span>
            <span>{{ $dispensation->total_cents->formatted() }}</span>
        </div>

        <h2>{{ __('Forma de contribución') }}</h2>
        <div class="row"><span class="muted">{{ __('Efectivo') }}</span><span>{{ $dispensation->cash_cents->formatted() }}</span></div>
        <div class="row"><span class="muted">{{ __('Monedero') }}</span><span>{{ $dispensation->wallet_cents->formatted() }}</span></div>

        <p class="foot">
            {{ __('Contribución de costes compartidos (aportación). Este documento no es una factura ni acredita una venta: el club es una asociación sin ánimo de lucro y dispensa a sus socios al coste.') }}
        </p>
    </div>

    <div class="actions">
        <button type="button" onclick="window.print()">{{ __('Imprimir') }}</button>
    </div>
</body>
</html>
