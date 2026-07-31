<x-layouts.socio :title="__('Invitación no disponible')" :nav="false">
    <div class="mx-auto max-w-sm">
        <div class="mb-5 text-center">
            <img src="/socio-icons/icon-192.png" width="56" height="56" alt="" class="mx-auto h-14 w-14 rounded-2xl shadow-sm">
            <h1 class="mt-3 text-xl font-semibold">{{ __('Invitación no disponible') }}</h1>
        </div>

        <div class="rounded-2xl border border-line bg-surface p-6 text-center shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <p class="text-3xl">⏳</p>
            <p class="mt-2 text-sm text-ink-muted dark:text-slate-300">{{ $reason }}</p>
            <p class="mt-3 text-xs text-ink-muted dark:text-slate-400">{{ __('Ponte en contacto con la asociación para obtener una nueva invitación.') }}</p>
        </div>
    </div>
</x-layouts.socio>
