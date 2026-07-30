<x-layouts.socio :title="__('Acceso socio/a')">
    <div class="rounded-xl bg-white p-6 shadow-sm">
        <h1 class="text-lg font-semibold">{{ __('Área de socio/a') }}</h1>
        <p class="mt-1 text-sm text-slate-600">{{ __('Introduce tu correo y te enviaremos un enlace de acceso.') }}</p>

        @if (session('status'))
            <p class="mt-4 rounded-lg bg-blue-50 p-3 text-sm text-blue-700">{{ session('status') }}</p>
        @endif
        @error('email')
            <p class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ $message }}</p>
        @enderror

        <form method="POST" action="{{ route('socio.login.send') }}" class="mt-4 space-y-3">
            @csrf
            <input type="email" name="email" required autocomplete="email"
                   placeholder="{{ __('tu@correo.es') }}"
                   class="w-full rounded-lg border border-slate-200 px-3 py-2">
            <button type="submit" class="w-full rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white">
                {{ __('Enviar enlace') }}
            </button>
        </form>
    </div>
</x-layouts.socio>
