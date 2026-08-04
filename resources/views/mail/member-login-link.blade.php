<x-mail.shell :message="$message" :title="__('Tu enlace de acceso')">
    <div style="text-align:center;">
        <p style="margin:0 0 16px;">{{ __('Hola :name,', ['name' => $memberName]) }}</p>
        <p style="margin:0 0 20px;color:#475569;">{{ __('Pulsa el botón para acceder a tu área de socio/a. El enlace caduca en :min minutos y solo puede usarse una vez.', ['min' => $ttl]) }}</p>
        <a href="{{ $url }}"
           style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:600;">
            {{ __('Acceder') }}
        </a>
        <p style="margin:20px 0 0;color:#94a3b8;font-size:12px;">
            {{ __('Si no has solicitado este enlace, puedes ignorar este correo.') }}
        </p>
    </div>
</x-mail.shell>
