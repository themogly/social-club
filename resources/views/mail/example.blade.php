<x-mail.shell :message="$message" :title="__('Ejemplo de comunicación del club')">
    <p style="margin:0 0 16px;">{{ __('Hola :name,', ['name' => $memberName]) }}</p>
    <p style="margin:0 0 16px;color:#475569;">
        {{ __('Esta es una plantilla de referencia. Las comunicaciones reales del club (bienvenida, renovación, avisos) reutilizan este diseño.') }}
    </p>
</x-mail.shell>
