<x-layouts.socio :title="__('Mi carné')">
    @php($money = fn (int $c) => \App\Support\Money::fromCents($c)->formatted())
    @php($grams = fn (int $cg) => \App\Support\Weight::fromCentigrams($cg)->formatted())

    {{-- QR membership card — cached by the service worker so it renders offline. --}}
    <section class="rounded-xl bg-white p-6 text-center shadow-sm" data-offline-card>
        <img src="data:image/png;base64,{{ base64_encode($qrPng) }}" alt="{{ __('Carné QR') }}"
             width="220" height="220" class="mx-auto rounded-lg border border-slate-200">
        <p class="mt-3 font-semibold">{{ $member->fullName() }}</p>
        <p class="text-sm text-slate-600">{{ __('Nº de socio/a: :no', ['no' => $member->member_no]) }}</p>
        <p class="mt-1 text-xs uppercase tracking-wide text-slate-500">{{ $member->status->value }}</p>
    </section>

    @if ($snapshot)
        <section class="mt-4 rounded-xl bg-white p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-slate-700">{{ __('Consumo del mes') }}</h2>
            <p class="mt-1 text-2xl font-semibold">{{ $grams($snapshot->monthlyUsedCg) }} / {{ $grams($snapshot->monthlyLimitCg) }}</p>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                <div class="h-full bg-blue-600" style="width: {{ min(100, $snapshot->monthlyPercent()) }}%"></div>
            </div>
            <p class="mt-2 text-xs text-slate-500">{{ __('Hoy: :used de :limit', ['used' => $grams($snapshot->dailyUsedCg), 'limit' => $grams($snapshot->dailyLimitCg)]) }}</p>
        </section>
    @endif

    <section class="mt-4 rounded-xl bg-white p-5 shadow-sm">
        <h2 class="text-sm font-semibold text-slate-700">{{ __('Monedero') }}</h2>
        <p class="mt-1 text-2xl font-semibold">{{ $money($walletCents) }}</p>
    </section>

    <nav class="mt-4 grid grid-cols-3 gap-2 text-center text-sm">
        <a href="{{ route('socio.menu') }}" class="rounded-lg bg-white p-3 shadow-sm">{{ __('Menú') }}</a>
        <a href="{{ route('socio.history') }}" class="rounded-lg bg-white p-3 shadow-sm">{{ __('Historial') }}</a>
        <a href="{{ route('socio.export') }}" class="rounded-lg bg-white p-3 shadow-sm">{{ __('Mis datos') }}</a>
    </nav>

    <form method="POST" action="{{ route('socio.logout') }}" class="mt-6 text-center">
        @csrf
        <button type="submit" class="text-sm text-slate-500 underline">{{ __('Cerrar sesión') }}</button>
    </form>
</x-layouts.socio>
