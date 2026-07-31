<x-layouts.socio :title="__('Historial')">
    @php($money = fn (int $c) => \App\Support\Money::fromCents($c)->formatted())
    @php($grams = fn (int $cg) => \App\Support\Weight::fromCentigrams($cg)->formatted())

    <header class="mb-4">
        <h1 class="text-xl font-semibold">{{ __('Historial') }}</h1>
    </header>

    <h2 class="mt-2 text-sm font-semibold text-ink-muted dark:text-slate-300">{{ __('Dispensaciones') }}</h2>
    @forelse ($dispensations as $d)
        <div class="mt-2 flex items-baseline justify-between rounded-xl border border-line bg-surface p-3 text-sm shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <span class="text-ink-muted dark:text-slate-300">{{ optional($d->dispensed_at)->format('d/m/Y') }} · {{ $grams((int) $d->lines->sum(fn ($l) => $l->grams_cg->centigrams)) }}</span>
            <span class="font-semibold">{{ $money($d->total_cents->cents) }}</span>
        </div>
    @empty
        <p class="mt-2 text-sm text-ink-muted dark:text-slate-400">{{ __('Sin dispensaciones.') }}</p>
    @endforelse

    <h2 class="mt-6 text-sm font-semibold text-ink-muted dark:text-slate-300">{{ __('Monedero') }}</h2>
    @forelse ($wallet as $w)
        <div class="mt-2 flex items-baseline justify-between rounded-xl border border-line bg-surface p-3 text-sm shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <span class="text-ink-muted dark:text-slate-300">{{ optional($w->created_at)->format('d/m/Y') }} · {{ $w->type->label() }}</span>
            <span class="font-semibold">{{ $money($w->amount_cents->cents) }}</span>
        </div>
    @empty
        <p class="mt-2 text-sm text-ink-muted dark:text-slate-400">{{ __('Sin movimientos.') }}</p>
    @endforelse
</x-layouts.socio>
