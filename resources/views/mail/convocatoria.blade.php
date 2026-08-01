<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Convocatoria de asamblea') }}</title>
</head>
<body style="margin:0;padding:0;background:#f8fafc;font-family:Inter,-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
                    <tr>
                        <td style="padding:24px;border-bottom:1px solid #e2e8f0;">
                            {{-- CID-embedded logo (PNG). Never a hot-linked asset() URL. --}}
                            <img src="{{ $message->embed(resource_path('mail/logo.png')) }}"
                                 width="240" height="64" alt="{{ config('app.name') }}"
                                 style="display:block;border:0;outline:none;">
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            <p style="margin:0 0 4px;text-transform:uppercase;letter-spacing:.04em;font-size:12px;color:#475569;">{{ __('Convocatoria de asamblea :type', ['type' => $typeLabel]) }}</p>
                            <h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;">{{ $title }}</h1>

                            <p style="margin:0 0 16px;">{{ __('Hola :name,', ['name' => $memberName]) }}</p>
                            <p style="margin:0 0 20px;color:#475569;">
                                {{ __('Por la presente se le convoca a la asamblea general de la asociación, que se celebrará:') }}
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;border:1px solid #e2e8f0;border-radius:8px;">
                                <tr>
                                    <td style="padding:10px 14px;color:#475569;width:40%;border-bottom:1px solid #f1f5f9;">{{ __('Fecha y hora') }}</td>
                                    <td style="padding:10px 14px;font-weight:600;border-bottom:1px solid #f1f5f9;">{{ $heldAt }}</td>
                                </tr>
                                @if ($secondCallAt)
                                    <tr>
                                        <td style="padding:10px 14px;color:#475569;border-bottom:1px solid #f1f5f9;">{{ __('Segunda convocatoria') }}</td>
                                        <td style="padding:10px 14px;border-bottom:1px solid #f1f5f9;">{{ $secondCallAt }}</td>
                                    </tr>
                                @endif
                                @if ($venue)
                                    <tr>
                                        <td style="padding:10px 14px;color:#475569;border-bottom:1px solid #f1f5f9;">{{ __('Lugar') }}</td>
                                        <td style="padding:10px 14px;border-bottom:1px solid #f1f5f9;">{{ $venue }}</td>
                                    </tr>
                                @endif
                                @if ($quorumRequired !== null)
                                    <tr>
                                        <td style="padding:10px 14px;color:#475569;">{{ __('Quórum requerido (primera convocatoria)') }}</td>
                                        <td style="padding:10px 14px;">{{ $quorumRequired }}</td>
                                    </tr>
                                @endif
                            </table>

                            @if (!empty($agenda))
                                <h2 style="margin:0 0 8px;font-size:15px;">{{ __('Orden del día') }}</h2>
                                <ol style="margin:0 0 20px;padding-left:20px;color:#475569;">
                                    @foreach ($agenda as $punto)
                                        <li style="margin:0 0 4px;">{{ $punto }}</li>
                                    @endforeach
                                </ol>
                            @endif

                            @if ($body)
                                <p style="margin:0 0 20px;color:#475569;white-space:pre-line;">{{ $body }}</p>
                            @endif

                            <p style="margin:24px 0 0;color:#475569;font-size:13px;">{{ __('Un saludo,') }}<br>{{ config('app.name') }}</p>
                        </td>
                    </tr>
                </table>
                <p style="max-width:600px;margin:16px auto 0;padding:0 24px;color:#94a3b8;font-size:11px;text-align:center;">
                    {{ __('Comunicación interna de la asociación dirigida a sus socios. No constituye asesoramiento legal.') }}
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
