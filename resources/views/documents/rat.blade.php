<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Registro de Actividades de Tratamiento') }}</title>
    <style>
        @page { margin: 28px 32px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 10px; }
        .head { border-bottom: 2px solid #2563eb; padding-bottom: 8px; margin-bottom: 12px; }
        .org { font-size: 11px; color: #475569; font-weight: bold; letter-spacing: .04em; text-transform: uppercase; }
        h1 { font-size: 18px; margin: 4px 0 2px; }
        .meta { font-size: 9px; color: #475569; }
        .controller { width: 100%; border-collapse: collapse; margin: 6px 0 12px; }
        .controller td { border: 1px solid #e2e8f0; padding: 5px 7px; vertical-align: top; width: 33%; }
        .controller .label { font-size: 8px; text-transform: uppercase; letter-spacing: .04em; color: #475569; display: block; }
        .controller .value { font-size: 11px; font-weight: bold; }
        .a9-note { border: 1px solid #dc2626; border-radius: 6px; padding: 7px 9px; margin-bottom: 12px; font-size: 9.5px; color: #7f1d1d; }
        .activity { border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 10px; margin-bottom: 8px; page-break-inside: avoid; }
        .activity h2 { font-size: 11px; margin: 0 0 4px; color: #1d4ed8; }
        .ref { color: #94a3b8; font-weight: normal; }
        .a9-badge { display: inline-block; background: #dc2626; color: #fff; border-radius: 4px; padding: 1px 5px; font-size: 7.5px; text-transform: uppercase; letter-spacing: .03em; margin-left: 4px; }
        .row { margin: 2px 0; }
        .k { font-size: 8px; text-transform: uppercase; letter-spacing: .03em; color: #475569; }
        .v { font-size: 9.5px; }
        ul { margin: 2px 0 2px 14px; padding: 0; }
        .security { border-top: 1px solid #e2e8f0; margin-top: 10px; padding-top: 8px; font-size: 9.5px; }
        .note { font-size: 8px; color: #94a3b8; font-style: italic; margin-top: 8px; }
        .foot { position: fixed; bottom: -12px; left: 0; right: 0; font-size: 8px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <div class="head">
        <div class="org">{{ $controller['name'] }}</div>
        <h1>{{ __('Registro de Actividades de Tratamiento') }}</h1>
        <div class="meta">{{ __('Artículo 30 RGPD') }} · {{ __('Generado') }} {{ $generatedAt->format('d/m/Y H:i') }}</div>
    </div>

    <table class="controller">
        <tr>
            <td><span class="label">{{ __('Responsable') }}</span><span class="value">{{ $controller['legal_name'] ?? $controller['name'] }}</span></td>
            <td><span class="label">{{ __('CIF/NIF') }}</span><span class="value">{{ $controller['tax_id'] ?? '—' }}</span></td>
            <td><span class="label">{{ __('Contacto') }}</span><span class="value">{{ $controller['contact_email'] ?? '—' }}</span></td>
        </tr>
        <tr>
            <td colspan="3"><span class="label">{{ __('Dirección') }}</span><span class="value">{{ $controller['address'] ?? '—' }}</span></td>
        </tr>
    </table>

    <div class="a9-note">
        <strong>{{ __('Datos de categoría especial (Art. 9 RGPD).') }}</strong>
        {{ __('El consumo de cannabis y la condición terapéutica son datos de salud: consentimiento explícito, base jurídica documentada, control de acceso reforzado y EIPD (DPIA).') }}
    </div>

    @foreach ($activities as $activity)
        <div class="activity">
            <h2><span class="ref">{{ $activity['ref'] }}</span> · {{ $activity['name'] }}
                @if ($activity['article_9'])<span class="a9-badge">{{ __('Art. 9 · salud') }}</span>@endif
            </h2>
            <div class="row"><span class="k">{{ __('Finalidad') }}:</span> <span class="v">{{ $activity['purpose'] }}</span></div>
            <div class="row"><span class="k">{{ __('Base jurídica') }}:</span> <span class="v">{{ $activity['legal_basis'] }}</span></div>
            <div class="row"><span class="k">{{ __('Categorías de datos') }}:</span>
                <ul>
                    @foreach ($activity['data_categories'] as $category)
                        <li class="v">{{ $category }}</li>
                    @endforeach
                </ul>
            </div>
            <div class="row"><span class="k">{{ __('Destinatarios') }}:</span> <span class="v">{{ $activity['recipients'] }}</span></div>
            <div class="row"><span class="k">{{ __('Transferencias') }}:</span> <span class="v">{{ $activity['transfers'] }}</span></div>
            <div class="row"><span class="k">{{ __('Conservación') }}:</span> <span class="v">{{ $activity['retention'] }}</span></div>
        </div>
    @endforeach

    <div class="security">
        <span class="k">{{ __('Medidas de seguridad (Art. 32 RGPD)') }}:</span> {{ $security }}
    </div>

    <p class="note">{{ __('Documento interno generado automáticamente. No constituye asesoramiento legal.') }}</p>
    <div class="foot">{{ $controller['name'] }} · {{ __('Registro de Actividades de Tratamiento') }}</div>
</body>
</html>
