<x-mail.shell :message="$message" :title="__('Tu carné de socio/a')">
    <div style="text-align:center;">
        <p style="margin:0 0 4px;">{{ __('Hola :name,', ['name' => $memberName]) }}</p>
        <p style="margin:0 0 16px;color:#475569;">{{ __('Nº de socio/a: :no', ['no' => $memberNo]) }}</p>
        {{-- QR embedded inline (CID/data URI), never hot-linked. --}}
        <img src="{{ $message->embedData($qrPng, 'member-qr.png', 'image/png') }}"
             width="220" height="220" alt="QR"
             style="display:block;margin:0 auto;border:1px solid #e2e8f0;border-radius:12px;">
        <p style="margin:16px 0 0;color:#475569;font-size:13px;">
            {{ __('Muestra este código en la entrada y en el mostrador.') }}
        </p>
    </div>
</x-mail.shell>
