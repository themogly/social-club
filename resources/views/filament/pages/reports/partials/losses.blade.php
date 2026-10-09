{{-- Prompt 367 — Pérdidas: the headline, the chart and the five sections, above the shared tables (by person, by sede, detail).
     Every figure is the LossesReport's; every line links to its list in the detail below. --}}
<div class="csc-losses">
    <div class="csc-cards" data-losses-headline>
        @foreach ($lossCards as $card)
            <x-dashboard.stat-card :card="$card" />
        @endforeach
    </div>

    <x-dashboard.section :title="$lossSeries['by_week'] ? __('Pérdidas por semana') : __('Pérdidas por día')">
        <p class="sr-only">{{ $lossSeriesLine }}</p>
        <div class="csc-loss-bars" aria-hidden="true" data-losses-chart>
            @foreach ($lossSeries['values'] as $i => $value)
                <div class="csc-loss-bar" title="{{ $lossSeries['labels'][$i] }}: {{ \App\Support\Money::fromCents($value)->formatted() }}">
                    <span class="csc-loss-bar-fill {{ $value < 0 ? 'csc-loss-bar-offset' : '' }}"
                        style="height: {{ max($value !== 0 ? 4 : 0, (int) round(abs($value) / $lossSeriesMax * 100)) }}%"></span>
                    <span class="csc-loss-bar-label">{{ $lossSeries['labels'][$i] }}</span>
                </div>
            @endforeach
        </div>
    </x-dashboard.section>

    <div class="csc-loss-sections">
        @foreach ($lossSections as $key => $section)
            <x-dashboard.section :title="$section['label']">
                <a href="{{ $this->detailUrl(['section' => $key, 'person' => null]) }}" class="csc-loss-total" data-losses-section="{{ $key }}">
                    <span>{{ __('Total') }}</span>
                    <strong>{{ \App\Support\Money::fromCents($section['total'])->formatted() }}</strong>
                </a>
                <ul class="csc-loss-lines">
                    {{-- Prompt 375 — a discount kind with nothing in the period is not listed here (the PDF and CSV keep every line). --}}
                    @foreach (array_filter($section['lines'], fn (array $l): bool => ! $l['info'] || $l['count'] > 0) as $line)
                        <li @class(['csc-loss-line', 'csc-loss-info' => $line['info'], 'csc-loss-offset' => ! $line['info'] && $line['cents'] < 0])>
                            <a href="{{ $this->detailUrl(['section' => $key, 'person' => null]) }}">
                                <span class="csc-loss-label">
                                    {{ $line['label'] }}
                                    @if ($line['grams'] !== 0)
                                        <span class="csc-loss-sub">{{ \App\Support\Weight::fromCentigrams($line['grams'])->formatted() }}</span>
                                    @endif
                                    @if ($key === 'existencias' && $line['contribution'] !== 0)
                                        <span class="csc-loss-sub">{{ __(':amount al precio de aportación', ['amount' => \App\Support\Money::fromCents($line['contribution'])->formatted()]) }}</span>
                                    @endif
                                </span>
                                {{-- Prompt 375 — a line whose every movement has no cost says so; a mixed one gives the amount and how many had none. --}}
                                <span class="csc-loss-amount">
                                    @if ($line['no_cost'] > 0 && $line['no_cost'] === $line['count'])
                                        <span class="csc-loss-nocost">{{ __('sin coste registrado') }}</span>
                                    @else
                                        {{ \App\Support\Money::fromCents($line['cents'])->formatted() }}
                                    @endif
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
                @if ($section['no_cost'] > 0)
                    <a href="{{ $section['no_cost_url'] }}" class="csc-loss-nocost-note" data-losses-no-cost="{{ $section['no_cost'] }}">
                        {{ trans_choice(':count movimiento sin coste: no está en el total|:count movimientos sin coste: no están en el total', $section['no_cost'], ['count' => $section['no_cost']]) }}
                        · {{ __('añade el coste del lote') }}
                    </a>
                @endif
            </x-dashboard.section>
        @endforeach
    </div>
</div>

<style>
    .csc-losses { display: flex; flex-direction: column; gap: 1rem; }
    .csc-loss-bars { display: flex; align-items: flex-end; gap: 0.35rem; height: 9rem; padding-bottom: 1.4rem; overflow-x: auto; }
    .csc-loss-bar { position: relative; flex: 1 0 1.4rem; height: 100%; display: flex; align-items: flex-end; justify-content: center; }
    .csc-loss-bar-fill { display: block; width: 100%; max-width: 2.5rem; background: var(--warn); border-radius: 0.3rem 0.3rem 0 0; }
    .csc-loss-bar-offset { background: var(--ok); }
    .csc-loss-bar-label { position: absolute; bottom: -1.3rem; font-size: 0.66rem; color: var(--mut); white-space: nowrap; }
    .csc-loss-sections { display: grid; grid-template-columns: 1fr; gap: 1rem; }
    @media (min-width: 1024px) { .csc-loss-sections { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (min-width: 1440px) { .csc-loss-sections { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    .csc-loss-total { display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem; text-decoration: none; color: var(--tx); font-size: 0.85rem; padding-bottom: 0.4rem; border-bottom: 1px solid var(--bd); }
    .csc-loss-total strong { font-size: 1.15rem; font-variant-numeric: tabular-nums; }
    .csc-loss-lines { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }
    .csc-loss-line a { display: flex; justify-content: space-between; gap: 0.75rem; padding: 0.45rem 0; min-height: 2.75rem; align-items: center; text-decoration: none; color: var(--tx); font-size: 0.82rem; border-bottom: 1px dashed var(--bd); }
    .csc-loss-line:last-child a { border-bottom: 0; }
    .csc-loss-line a:hover .csc-loss-label { color: var(--brtx); }
    .csc-loss-label { display: flex; flex-direction: column; gap: 0.1rem; min-width: 0; }
    .csc-loss-sub { font-size: 0.72rem; color: var(--mut); }
    .csc-loss-amount { font-variant-numeric: tabular-nums; font-weight: 600; white-space: nowrap; }
    .csc-loss-info a { color: var(--mut); }
    .csc-loss-offset .csc-loss-amount { color: var(--okt); }
    .csc-loss-nocost { font-weight: 600; color: var(--warnt); }
    .csc-loss-nocost-note { display: block; font-size: 0.75rem; font-weight: 600; color: var(--warnt); text-decoration: none; padding-top: 0.4rem; border-top: 1px solid var(--bd); }
    .csc-loss-nocost-note:hover { text-decoration: underline; }
</style>
