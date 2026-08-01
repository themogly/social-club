{{-- Counter help (prompt 92): not a wall of prose, but answers to the blocked states staff actually hit,
     and the club's terms. Static + Alpine, on every counter screen via the shared header — nothing loads,
     nothing slows the till. Rules are NAMED, never hard-coded to a value (the limits are Settings). --}}
<div x-data="{ open: false }" class="relative" data-counter-help>
    <button
        type="button"
        @click="open = ! open"
        aria-haspopup="true"
        :aria-expanded="open.toString()"
        aria-label="{{ __('Ayuda') }}"
        class="flex h-9 w-9 items-center justify-center rounded-lg text-ink-muted transition hover:bg-brand-tint hover:text-brand dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"
    >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z"/>
        </svg>
    </button>

    <div
        x-show="open"
        x-cloak
        @click.outside="open = false"
        @keydown.escape.window="open = false"
        class="absolute right-0 z-40 mt-1 w-80 max-w-[90vw] rounded-xl border border-line bg-surface p-3 text-left shadow-lg dark:border-slate-700 dark:bg-slate-900"
    >
        <h2 class="text-sm font-semibold">{{ __('¿Por qué no puedo dispensar a un socio?') }}</h2>
        <ul class="mt-2 space-y-1.5 text-sm text-ink-muted dark:text-slate-400">
            <li>· {{ __('No tiene una membresía activa, o no está al corriente de la cuota.') }}</li>
            <li>· {{ __('Está en carencia: el período de espera desde el alta aún no ha terminado.') }}</li>
            <li>· {{ __('Alcanzaría el límite diario o mensual de gramos configurado.') }}</li>
            <li>· {{ __('No cumple la edad mínima, o su documento ha caducado.') }}</li>
            <li>· {{ __('Debe dinero por encima del umbral permitido en el monedero.') }}</li>
        </ul>
        <p class="mt-2 text-xs text-ink-muted dark:text-slate-400">{{ __('El mostrador te dice el motivo exacto en cada caso. Un responsable puede autorizar algunas excepciones.') }}</p>

        <h3 class="mt-3 text-sm font-semibold">{{ __('Términos') }}</h3>
        <dl class="mt-1 space-y-1 text-xs">
            @foreach (['Aportación', 'Dispensación', 'Carencia', 'Arqueo'] as $term)
                <div>
                    <dt class="inline font-semibold text-ink dark:text-slate-200">{{ $term }}:</dt>
                    <dd class="inline text-ink-muted dark:text-slate-400">{{ __(\App\Support\Help::GLOSSARY[$term]) }}</dd>
                </div>
            @endforeach
        </dl>
    </div>
</div>
