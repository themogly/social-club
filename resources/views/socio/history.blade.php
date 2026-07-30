<x-layouts.socio :title="__('Historial')">
    @php($money = fn (int $c) => \App\Support\Money::fromCents($c)->formatted())
    @php($grams = fn (int $cg) => \App\Support\Weight::fromCentigrams($cg)->formatted())
    <a href="{{ route('socio.home') }}" class="text-sm text-slate-500">&larr; {{ __('Volver') }}</a>
    <h1 class="mt-2 text-lg font-semibold">{{ __('Historial') }}</h1>

    <h2 class="mt-4 text-sm font-semibold text-slate-700">{{ __('Dispensaciones') }}</h2>
    @forelse ($dispensations as $d)
        <div class="mt-2 flex items-baseline justify-between rounded-lg bg-white p-3 text-sm shadow-sm">
            <span>{{ optional($d->dispensed_at)->format('d/m/Y') }} · {{ $grams((int) $d->lines->sum(fn ($l) => $l->grams_cg->centigrams)) }}</span>
            <span class="font-semibold">{{ $money($d->total_cents->cents) }}</span>
        </div>
    @empty
        <p class="mt-2 text-sm text-slate-500">{{ __('Sin dispensaciones.') }}</p>
    @endforelse

    <h2 class="mt-6 text-sm font-semibold text-slate-700">{{ __('Monedero') }}</h2>
    @forelse ($wallet as $w)
        <div class="mt-2 flex items-baseline justify-between rounded-lg bg-white p-3 text-sm shadow-sm">
            <span>{{ optional($w->created_at)->format('d/m/Y') }} · {{ $w->type->value }}</span>
            <span class="font-semibold">{{ $money($w->amount_cents->cents) }}</span>
        </div>
    @empty
        <p class="mt-2 text-sm text-slate-500">{{ __('Sin movimientos.') }}</p>
    @endforelse
</x-layouts.socio>
