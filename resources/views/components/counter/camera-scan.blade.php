{{--
    Camera QR scan (prompt 35) — a PROGRESSIVE ENHANCEMENT over the wedge scanner + name
    search, gated per location by camera_scan_enabled. On BarcodeDetector-capable browsers
    (Chrome/Edge/Android) it shows a "scan with camera" trigger; where the API is missing the
    trigger hides itself (Alpine `supported`) and the manual inputs remain — the counter is
    never blocked. A decoded QR routes through the SAME server lookup as the wedge scanner
    (`$wire.submitCameraScan` → ResolveMemberByToken). The Alpine behaviour lives in
    resources/js/app.js; translated copy is passed in here. Camera access needs a secure
    context (HTTPS or localhost).
--}}
<div
    x-cloak
    x-data="cameraScan({ messages: {
        camera: @js(__('No se pudo acceder a la cámara. Revisa los permisos del navegador o usa el lector.')),
        unsupported: @js(__('Este navegador no admite el escaneo con cámara aquí.')),
    } })"
    x-on:livewire:navigating.window="teardown()"
    class="mt-3"
>
    <x-button
        variant="secondary"
        size="md"
        x-show="supported"
        x-on:click="openScanner()"
        class="w-full gap-2"
    >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-5 w-5" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z" />
        </svg>
        {{ __('Escanear con cámara') }}
    </x-button>

    {{-- Full-screen camera overlay while scanning. --}}
    <div
        x-show="active"
        x-cloak
        role="dialog"
        aria-modal="true"
        aria-label="{{ __('Escanear tarjeta de socio con la cámara') }}"
        x-on:keydown.escape.window="closeScanner()"
        class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-4 bg-black/80 p-4"
    >
        <div class="relative w-full max-w-md overflow-hidden rounded-2xl bg-black shadow-xl">
            <video x-ref="video" playsinline muted class="aspect-square w-full object-cover"></video>
            <div class="pointer-events-none absolute inset-0 flex items-center justify-center">
                <div class="h-48 w-48 rounded-2xl border-2 border-white/80"></div>
            </div>
        </div>

        <p x-show="error" x-cloak x-text="error" role="alert" class="max-w-md rounded-lg bg-error px-4 py-2 text-center text-sm font-semibold text-white"></p>
        <p x-show="! error" class="text-sm font-medium text-white/90">{{ __('Apunta la cámara al código QR de la tarjeta del socio.') }}</p>

        <x-button variant="secondary" size="md" x-on:click="closeScanner()">{{ __('Cerrar') }}</x-button>
    </div>
</div>
