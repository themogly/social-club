<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Reactivar el acceso del club') }}</title>
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
                        <td style="padding:24px;text-align:center;">
                            @if ($isDrill)
                                <p style="margin:0 0 16px;font-weight:600;color:#d97706;">{{ __('Esto es un simulacro.') }}</p>
                            @endif
                            <p style="margin:0 0 16px;">{{ __('Hola :name,', ['name' => $ownerName]) }}</p>
                            <p style="margin:0 0 20px;color:#475569;">{{ __('Se ha activado el bloqueo de seguridad del club. Cuando sea seguro hacerlo, pulsa el botón para reactivar el acceso. El enlace caduca en :h horas y solo puede usarse una vez.', ['h' => $ttlHours]) }}</p>
                            <a href="{{ $url }}"
                               style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:600;">
                                {{ __('Reactivar el acceso') }}
                            </a>
                            <p style="margin:20px 0 0;color:#94a3b8;font-size:12px;">
                                {{ __('Si no reconoces este bloqueo, no reactives y contacta con el resto del equipo. El acceso se restablecerá solo pasado el plazo de seguridad.') }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
