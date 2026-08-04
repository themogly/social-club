<x-mail.shell :message="$message" :title="__('Tu membresía vence pronto')">
    <p style="margin:0 0 16px;">{{ __('Hola :name,', ['name' => $memberName]) }}</p>
    <p style="margin:0 0 16px;color:#475569;">
        {{ __('Tu membresía vence el :date. Renuévala en tu próxima visita para seguir disfrutando de la asociación.', ['date' => $expiresOn]) }}
    </p>
</x-mail.shell>
