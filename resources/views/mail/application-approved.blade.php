<x-mail.shell :message="$message" :title="__('Tu solicitud ha sido aprobada')">
    <p style="margin:0 0 16px;">{{ __('Hola :name,', ['name' => $memberName]) }}</p>
    <p style="margin:0 0 16px;color:#475569;">
        {{ __('¡Bienvenido/a a la asociación! Tu solicitud ha sido aprobada y ya eres socio/a.') }}
    </p>
    <p style="margin:0 0 20px;color:#475569;">
        {{ __('Tu número de socio/a es :no. Recibirás tu carné con el código QR en un correo aparte.', ['no' => $memberNo]) }}
    </p>
    <a href="{{ $loginUrl }}"
       style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:600;">
        {{ __('Acceder a mi área de socio/a') }}
    </a>
</x-mail.shell>
