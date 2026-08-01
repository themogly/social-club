@php
    use App\Enums\MemberDocumentType;
    $titles = [
        MemberDocumentType::REGISTRATION_FORM->value => __('Solicitud de alta'),
        MemberDocumentType::DECLARATION->value => __('Previsión de consumo'),
        MemberDocumentType::SANCTION_ACT->value => __('Acta de sanción'),
        MemberDocumentType::CONSENT->value => __('Consentimiento'),
    ];
    $title = $titles[$type->value] ?? $type->value;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 12px; line-height: 1.5; }
        h1 { font-size: 18px; color: #1d4ed8; margin: 0 0 4px; }
        .muted { color: #475569; }
        table { width: 100%; border-collapse: collapse; margin: 16px 0; }
        td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        td.k { width: 38%; color: #475569; }
        .body { margin: 16px 0; white-space: pre-line; }
        .sign { margin-top: 48px; }
        .sign td { border: none; padding-top: 40px; border-top: 1px solid #0f172a; text-align: center; }
        .foot { margin-top: 24px; color: #94a3b8; font-size: 10px; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <p class="muted">{{ $orgName }} · {{ __('Nº de socio/a: :no', ['no' => $snapshot['member_no']]) }}</p>

    <table>
        <tr><td class="k">{{ __('Socio/a') }}</td><td>{{ $snapshot['nombre'] }}</td></tr>
        <tr><td class="k">{{ __('Documento') }}</td><td>{{ $snapshot['documento'] }}</td></tr>
        @if ($type->value === App\Enums\MemberDocumentType::DECLARATION->value && $snapshot['declared_monthly_cg'])
            <tr><td class="k">{{ __('Previsión mensual declarada') }}</td><td>{{ App\Support\Weight::fromCentigrams((int) $snapshot['declared_monthly_cg'])->formatted() }}</td></tr>
        @endif
        <tr><td class="k">{{ __('Versión del consentimiento') }}</td><td>{{ $snapshot['consent_version'] }}</td></tr>
        <tr><td class="k">{{ __('Fecha de emisión') }}</td><td>{{ \Illuminate\Support\Carbon::parse($snapshot['generated_at'])->format('d/m/Y H:i') }}</td></tr>
    </table>

    <div class="body">{{ $templateBody ?? __('Declaro que los datos son ciertos y me comprometo al consumo personal y a la no distribución, conforme a los estatutos de la asociación.') }}</div>

    <table class="sign">
        <tr>
            <td>{{ __('Firma del socio/a') }}</td>
            <td>{{ __('Por la asociación') }}</td>
        </tr>
    </table>

    <p class="foot">{{ __('Documento generado por la asociación como registro interno. No constituye asesoramiento legal.') }}</p>
</body>
</html>
