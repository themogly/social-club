@props(['member', 'source' => 'counter'])

{{--
    Member identity-photo capture (prompt 157) — a PROGRESSIVE ENHANCEMENT. The upload fallback (a file input)
    is ALWAYS present; the live-camera trigger shows itself only where getUserMedia exists (Alpine `supported`),
    so a tablet with no camera, a denied permission or an unsupported browser still captures a photo by upload —
    the counter is never blocked. Both paths POST to counter.members.photo → CaptureMemberPhoto (encrypted,
    private disk, signed-URL display, audited). The Alpine behaviour lives in resources/js/app.js. Camera access
    needs a secure context (HTTPS or localhost).

    This photo is compared by a HUMAN at the counter — the copy says so. It is not automated face matching.
--}}
<div
    x-data="photoCapture({
        endpoint: @js(route('counter.members.photo', ['member' => $member->id])),
        csrf: @js(csrf_token()),
        source: @js($source),
        messages: {
            camera: @js(__('No se pudo acceder a la cámara. Revisa los permisos o usa «Subir archivo».')),
            failed: @js(__('No se pudo guardar la foto. Inténtalo de nuevo.')),
        },
    })"
    x-on:livewire:navigating.window="teardown()"
    {{ $attributes }}
>
    <div class="flex flex-wrap items-center gap-2">
        <x-button variant="secondary" size="sm" x-show="supported" x-cloak x-on:click="open()" class="gap-2">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-5 w-5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z" />
            </svg>
            {{ __('Hacer foto') }}
        </x-button>

        {{-- Upload fallback — always available, whatever the browser. --}}
        <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-line bg-surface px-3 py-1.5 text-sm font-medium text-ink shadow-sm hover:border-brand focus-within:ring-2 focus-within:ring-brand/30 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            <input type="file" accept="image/*" capture="user" class="sr-only" x-on:change="fromFile($event)">
            {{ __('Subir archivo') }}
        </label>
    </div>

    <p x-show="error" x-cloak x-text="error" role="alert" class="mt-2 rounded-lg bg-error/10 px-3 py-1.5 text-sm font-medium text-error"></p>

    {{-- Full-screen camera overlay while capturing. --}}
    <div
        x-show="active"
        x-cloak
        role="dialog"
        aria-modal="true"
        aria-label="{{ __('Hacer foto del socio') }}"
        x-on:keydown.escape.window="close()"
        class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-4 bg-black/80 p-4"
    >
        <div class="relative w-full max-w-sm overflow-hidden rounded-2xl bg-black shadow-xl">
            <video x-ref="video" x-show="! preview" playsinline muted class="aspect-[3/4] w-full object-cover"></video>
            <img x-show="preview" x-cloak :src="preview" alt="" class="aspect-[3/4] w-full object-cover">
            <div x-show="! preview" class="pointer-events-none absolute inset-0 flex items-center justify-center">
                <div class="h-56 w-44 rounded-[40%] border-2 border-white/70"></div>
            </div>
        </div>

        <p x-show="error" x-cloak x-text="error" role="alert" class="max-w-sm rounded-lg bg-error px-4 py-2 text-center text-sm font-semibold text-white"></p>
        <p x-show="! error" class="max-w-sm text-center text-sm font-medium text-white/90">{{ __('Encuadra la cara del socio. Esta foto se comparará con la persona en el mostrador.') }}</p>

        <div class="flex flex-wrap items-center justify-center gap-2">
            <x-button size="md" x-show="! preview" x-on:click="capture()">{{ __('Capturar') }}</x-button>
            <x-button variant="secondary" size="md" x-show="preview" x-cloak x-on:click="retake()">{{ __('Repetir') }}</x-button>
            <x-button size="md" x-show="preview" x-cloak x-on:click="confirm()" x-bind:disabled="busy">{{ __('Guardar foto') }}</x-button>
            <x-button variant="secondary" size="md" x-on:click="close()">{{ __('Cerrar') }}</x-button>
        </div>
    </div>
</div>
