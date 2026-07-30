<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Tu membresía vence pronto') }}</title>
</head>
<body style="margin:0;padding:0;background:#f8fafc;font-family:Inter,-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
                    <tr>
                        <td style="padding:24px;border-bottom:1px solid #e2e8f0;">
                            <img src="{{ $message->embed(resource_path('mail/logo.png')) }}" width="240" height="64" alt="CSC platform" style="display:block;border:0;">
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            <p style="margin:0 0 16px;">{{ __('Hola :name,', ['name' => $memberName]) }}</p>
                            <p style="margin:0 0 16px;color:#475569;">
                                {{ __('Tu membresía vence el :date. Renuévala en tu próxima visita para seguir disfrutando de la asociación.', ['date' => $expiresOn]) }}
                            </p>
                            <p style="margin:24px 0 0;color:#475569;font-size:13px;">{{ __('Un saludo,') }}<br>{{ config('app.name') }}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
