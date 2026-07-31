<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Invitación para asociarte') }}</title>
</head>
<body style="margin:0;padding:0;background:#f8fafc;font-family:Inter,-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
                    <tr>
                        <td style="padding:24px;border-bottom:1px solid #e2e8f0;">
                            <img src="{{ $message->embed(resource_path('mail/logo.png')) }}" width="240" height="64" alt="{{ config('app.name') }}" style="display:block;border:0;">
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;text-align:center;">
                            <p style="margin:0 0 16px;">{{ __('Te damos la bienvenida.') }}</p>
                            <p style="margin:0 0 20px;color:#475569;">{{ __('Has recibido una invitación para asociarte. Pulsa el botón para completar tu solicitud de alta. El enlace caduca el :date.', ['date' => $expiresOn]) }}</p>
                            <a href="{{ $url }}"
                               style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:600;">
                                {{ __('Completar mi solicitud') }}
                            </a>
                            <p style="margin:20px 0 0;color:#94a3b8;font-size:12px;">
                                {{ __('Si no esperabas esta invitación, puedes ignorar este correo.') }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
