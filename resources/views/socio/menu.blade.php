<x-layouts.socio :title="__('Menú')">
    <header class="mb-4">
        <h1 class="text-xl font-semibold">{{ __('Menú del club') }}</h1>
        <p class="mt-0.5 text-sm text-ink-muted dark:text-slate-400">{{ __('Precios a tu tarifa. Sólo visible para socios/as.') }}</p>
    </header>

    @forelse ($genetics as $item)
        <article class="mt-3 rounded-2xl border border-line bg-surface p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-baseline justify-between gap-3">
                <h2 class="font-semibold">{{ $item['genetic']->name }}</h2>
                <span class="shrink-0 font-semibold text-brand dark:text-slate-100">{{ \App\Support\Money::fromCents($item['price']->ratePerGramCents)->formatted() }}/g</span>
            </div>
            <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">
                THC {{ $item['genetic']->thc_pct ?? '—' }}% · CBD {{ $item['genetic']->cbd_pct ?? '—' }}%@if ($item['genetic']->strain_type) · {{ $item['genetic']->strain_type->label() }}@endif
            </p>

            {{-- Prompt 185 — availability at THIS member's sede, as a state and never a quantity.

                 An unavailable genetic stays on the menu rather than disappearing: a member who has been
                 asking for it every week learns nothing from its absence, and a strain vanishing reads as
                 the club having stopped carrying it. Saying "sin existencias" is the useful answer and is
                 what a future "tell me when it's back" would hang off. --}}
            @php
                $availability = $item['availability'];
                $availabilityChip = match ($availability) {
                    \App\Models\Genetic::AVAILABLE => ['border-success/30 bg-success/10 text-success', __('Disponible')],
                    \App\Models\Genetic::LOW => ['border-warning/30 bg-warning/10 text-warning', __('Quedan pocas')],
                    default => ['border-line bg-surface-alt text-ink-muted dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400', __('Sin existencias')],
                };
            @endphp
            <p class="mt-2">
                <span
                    data-availability="{{ $availability }}"
                    class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ $availabilityChip[0] }}"
                >{{ $availabilityChip[1] }}</span>
            </p>
        </article>
    @empty
        <div class="mt-6 rounded-2xl border border-dashed border-line bg-surface p-8 text-center dark:border-slate-800 dark:bg-slate-900">
            <p class="text-sm text-ink-muted dark:text-slate-400">{{ __('Aún no hay genéticas publicadas en tu sede.') }}</p>
        </div>
    @endforelse
</x-layouts.socio>
