{{-- Prompt 342 — *Salir sin enviar*: said plainly, and nothing else to tap. The invitation link still works. --}}
<x-layouts.socio :title="__('Solicitud no enviada')" :nav="false">
    <div class="mx-auto max-w-sm">
        <div class="mb-5 text-center">
            <img src="/socio-icons/icon-192.png" width="56" height="56" alt="" class="mx-auto h-14 w-14 rounded-2xl shadow-sm">
            <h1 class="mt-3 text-xl font-semibold">{{ __('Solicitud no enviada') }}</h1>
        </div>
        <div data-application-left class="rounded-2xl border border-line bg-surface p-6 text-center shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <p class="text-sm">{{ __('No se ha enviado nada.') }}</p>
            <p class="mt-2 text-sm text-ink-muted dark:text-slate-400">{{ $expires !== null
                ? __('Puedes volver con el mismo enlace hasta el :date.', ['date' => local_datetime($expires, 'd/m/Y')])
                : __('Puedes volver con el mismo enlace.') }}</p>
        </div>
    </div>
</x-layouts.socio>
