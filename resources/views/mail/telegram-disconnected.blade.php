<x-mail.shell :message="$message" :title="__('Tus avisos llegarán por correo')">
    <p style="margin:0 0 16px;">{{ __('Hola :name,', ['name' => $name]) }}</p>
    <p style="margin:0 0 16px;color:#475569;">
        {{ __('Telegram ya no entrega los avisos a tu cuenta (el bot se ha bloqueado o eliminado). A partir de ahora te llegarán en el correo de la mañana. Puedes volver a conectar Telegram desde tu perfil, en Avisos.') }}
    </p>
</x-mail.shell>
