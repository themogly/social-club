<x-mail.shell :message="$message" :title="__('Invitación para asociarte')">
    <div style="text-align:center;">
        <p style="margin:0 0 16px;">{{ __('Te damos la bienvenida.') }}</p>
        <p style="margin:0 0 20px;color:#475569;">{{ __('Has recibido una invitación para asociarte. Pulsa el botón para completar tu solicitud de alta. El enlace caduca el :date.', ['date' => $expiresOn]) }}</p>
        <a href="{{ $url }}"
           style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:600;">
            {{ __('Completar mi solicitud') }}
        </a>
        <p style="margin:20px 0 0;color:#94a3b8;font-size:12px;">
            {{ __('Si no esperabas esta invitación, puedes ignorar este correo.') }}
        </p>
    </div>
</x-mail.shell>
