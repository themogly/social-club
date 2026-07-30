<x-layouts.socio :title="__('Menú')">
    <a href="{{ route('socio.home') }}" class="text-sm text-slate-500">&larr; {{ __('Volver') }}</a>
    <h1 class="mt-2 text-lg font-semibold">{{ __('Menú del club') }}</h1>
    <p class="text-sm text-slate-600">{{ __('Precios a tu tarifa. Sólo visible para socios/as.') }}</p>

    @forelse ($genetics as $item)
        <article class="mt-3 rounded-xl bg-white p-4 shadow-sm">
            <div class="flex items-baseline justify-between">
                <h2 class="font-semibold">{{ $item['genetic']->name }}</h2>
                <span class="font-semibold text-blue-700">{{ \App\Support\Money::fromCents($item['price']->ratePerGramCents)->formatted() }}/g</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">
                THC {{ $item['genetic']->thc_pct ?? '—' }}% · CBD {{ $item['genetic']->cbd_pct ?? '—' }}%
            </p>
        </article>
    @empty
        <p class="mt-6 rounded-xl bg-white p-6 text-center text-sm text-slate-500">
            {{ __('Aún no hay genéticas publicadas en tu sede.') }}
        </p>
    @endforelse
</x-layouts.socio>
