<x-mail.shell :message="$message" :title="__('Sobre tu solicitud de socio/a')">
    <p style="margin:0 0 16px;">{{ __('Hola :name,', ['name' => $applicantName]) }}</p>
    <p style="margin:0 0 16px;color:#475569;">
        {{ __('Gracias por tu interés en la asociación. Lamentamos comunicarte que, por ahora, no podemos aceptar tu solicitud.') }}
    </p>
    @if ($reason)
        <p style="margin:0 0 16px;padding:12px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;color:#475569;">
            <strong style="color:#0f172a;">{{ __('Motivo:') }}</strong> {{ $reason }}
        </p>
    @endif
    <p style="margin:0;color:#475569;">
        {{ __('Si crees que se trata de un error, ponte en contacto con la asociación.') }}
    </p>
</x-mail.shell>
