{{-- Prompt 363 — an alert sent by email because Telegram rejected the bot token. --}}
<x-mail.shell :message="$message" :title="__('Aviso del club')">
    <p style="margin:0 0 16px;white-space:pre-line;">{{ $text }}</p>
    <p style="margin:0;color:#475569;font-size:13px;">{{ __('Te llega por correo porque Telegram no está entregando los avisos ahora mismo. El club ya lo sabe.') }}</p>
</x-mail.shell>
