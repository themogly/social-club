<x-layouts.socio :title="__('Acceso socio/a')" :nav="false">
    <div class="mx-auto flex min-h-[70vh] max-w-sm flex-col justify-center">
        <div class="mb-6 text-center">
            <img src="/socio-icons/icon-192.png" width="64" height="64" alt=""
                 class="mx-auto h-16 w-16 rounded-2xl shadow-sm">
            <h1 class="mt-4 text-xl font-semibold">{{ __('Área de socio/a') }}</h1>
            <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">{{ __('Introduce tu correo y te enviaremos un enlace de acceso.') }}</p>
        </div>

        <div class="rounded-2xl border border-line bg-surface p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            @if (session('status'))
                <p class="mb-4 rounded-lg bg-brand-tint p-3 text-sm text-brand dark:bg-slate-800 dark:text-slate-200">{{ session('status') }}</p>
            @endif
            @error('email')
                <p class="mb-4 rounded-lg bg-error/10 p-3 text-sm text-error">{{ $message }}</p>
            @enderror

            <form method="POST" action="{{ route('socio.login.send') }}" class="space-y-3">
                @csrf
                <label class="block text-sm font-medium" for="email">{{ __('Correo electrónico') }}</label>
                <input type="email" id="email" name="email" required autocomplete="email" inputmode="email"
                       placeholder="{{ __('tu@correo.es') }}"
                       class="{{ \App\Support\SocioForm::FIELD }}">
                <x-button type="submit" size="md" class="w-full">{{ __('Enviar enlace') }}</x-button>
            </form>
        </div>

        <p class="mt-6 text-center text-xs text-ink-muted dark:text-slate-500">{{ __('Espacio privado para personas socias. No es un servicio público.') }}</p>
    </div>
</x-layouts.socio>
