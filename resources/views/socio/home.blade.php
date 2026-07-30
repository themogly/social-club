<x-layouts.socio :title="__('Mi carné')">
    @php($money = fn (int $c) => \App\Support\Money::fromCents($c)->formatted())
    @php($grams = fn (int $cg) => \App\Support\Weight::fromCentigrams($cg)->formatted())

    {{-- QR membership card — the hero. The QR is an inline data: URI, so caching the
         home HTML in the service worker is enough for it to render fully offline. --}}
    <section class="overflow-hidden rounded-2xl border border-line bg-surface shadow-sm dark:border-slate-800 dark:bg-slate-900"
             data-offline-card>
        <div class="bg-brand px-5 py-4 text-white">
            <p class="text-xs font-medium uppercase tracking-wide text-white/80">{{ __('Carné de socio/a') }}</p>
            <p class="mt-0.5 text-lg font-semibold leading-tight">{{ $member->fullName() }}</p>
            <p class="text-sm text-white/85">{{ __('Nº :no', ['no' => $member->member_no]) }}</p>
        </div>
        <div class="p-6 text-center">
            <img src="data:image/png;base64,{{ base64_encode($qrPng) }}" alt="{{ __('Carné QR') }}"
                 width="240" height="240"
                 class="mx-auto aspect-square w-60 max-w-full rounded-xl border border-line bg-white p-2 dark:border-slate-700">
            <p class="mt-4 inline-flex items-center gap-1.5 rounded-full bg-brand-tint px-3 py-1 text-xs font-medium uppercase tracking-wide text-brand dark:bg-slate-800 dark:text-slate-200">
                {{ __('Estado') }}: {{ $member->status->value }}
            </p>
            <p class="mt-3 text-xs text-ink-muted dark:text-slate-400">{{ __('Muestra este código en la entrada y en el mostrador.') }}</p>
        </div>
    </section>

    @if ($snapshot)
        <section class="mt-4 rounded-2xl border border-line bg-surface p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-ink-muted dark:text-slate-300">{{ __('Consumo del mes') }}</h2>
                <span class="text-xs text-ink-muted dark:text-slate-400">{{ min(100, $snapshot->monthlyPercent()) }}%</span>
            </div>
            <p class="mt-1 text-2xl font-semibold">{{ $grams($snapshot->monthlyUsedCg) }} <span class="text-base font-normal text-ink-muted dark:text-slate-400">/ {{ $grams($snapshot->monthlyLimitCg) }}</span></p>
            <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-brand-tint dark:bg-slate-800">
                <div class="h-full rounded-full bg-brand" style="width: {{ min(100, $snapshot->monthlyPercent()) }}%"></div>
            </div>
            <p class="mt-2 text-xs text-ink-muted dark:text-slate-400">{{ __('Hoy: :used de :limit', ['used' => $grams($snapshot->dailyUsedCg), 'limit' => $grams($snapshot->dailyLimitCg)]) }}</p>
        </section>
    @endif

    <div class="mt-4 grid grid-cols-2 gap-3">
        <section class="rounded-2xl border border-line bg-surface p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <h2 class="text-sm font-semibold text-ink-muted dark:text-slate-300">{{ __('Monedero') }}</h2>
            <p class="mt-1 text-2xl font-semibold">{{ $money($walletCents) }}</p>
        </section>
        <a href="{{ route('socio.events') }}"
           class="flex flex-col justify-between rounded-2xl border border-line bg-surface p-5 shadow-sm transition hover:border-brand/50 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="text-sm font-semibold text-ink-muted dark:text-slate-300">{{ __('Eventos') }}</h2>
            <span class="mt-1 text-sm font-medium text-brand dark:text-slate-100">{{ __('Ver y confirmar') }} &rarr;</span>
        </a>
    </div>

    <a href="{{ route('socio.export') }}"
       class="mt-3 flex items-center justify-between rounded-2xl border border-line bg-surface p-4 text-sm shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <span class="font-medium">{{ __('Descargar mis datos (RGPD)') }}</span>
        <span class="text-ink-muted dark:text-slate-400">&darr;</span>
    </a>

    <form method="POST" action="{{ route('socio.logout') }}" class="mt-6 text-center">
        @csrf
        <button type="submit" class="text-sm text-ink-muted underline dark:text-slate-400">{{ __('Cerrar sesión') }}</button>
    </form>
</x-layouts.socio>
