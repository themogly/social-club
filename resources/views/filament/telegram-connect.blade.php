{{-- Prompt 311 — the one-time Telegram link (10 minutes, single use), as a QR code for the phone and as a button on the
     phone itself. Opening it shows the bot; tapping *Start* connects. --}}
<div class="flex flex-col items-center gap-4 text-center">
    <p class="text-sm text-gray-600 dark:text-gray-300">
        {{ __('Escanea el código con el móvil (o pulsa el botón en el móvil) y toca «Iniciar» en Telegram. El enlace caduca en 10 minutos.') }}
    </p>
    <img src="{{ $qr }}" alt="{{ __('Código QR para conectar Telegram') }}" width="220" height="220" class="rounded-lg bg-white p-2" data-telegram-qr>
    <x-filament::button tag="a" :href="$url" data-telegram-link>{{ __('Abrir Telegram') }}</x-filament::button>
</div>
