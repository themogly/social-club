{{-- Prompt 371 — «Hoy»: the day's sheet. Every sale at this sede today, oldest first (the order a paper sheet is written in),
     and the totals at the bottom. Euros only for reports.view (the home panel's rule). A row opens its receipt in the counter's
     one receipt sheet (252: never a new tab). «Imprimir» prints this page as a clean sheet (the print styles below). --}}
<div>
    @include('livewire.counter.partials.counter-surface')

    @if (! $this->handoverActive())
    @php
        $blocker = \App\Support\CounterBlocker::first([
            \App\Support\CounterBlocker::SEDE => ! $noLocation,
            \App\Support\CounterBlocker::OPERATOR => $this->hasOperator(),
        ]);
        $money = fn (int $cents): string => \App\Support\Money::fromCents($cents)->formatted();
        $chipOn = 'border-brand bg-brand text-white';
        $chipOff = 'border-line bg-surface text-ink-muted hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300';
    @endphp

    @if (\App\Support\CounterBlocker::rendersInPage($blocker))
        <x-counter.blocking-state
            data-blocker="sede"
            icon="map-pin"
            :heading="$mustChooseLocation ? __('Elige tu sede') : __('Sin sede asignada')"
            :body="$mustChooseLocation ? __('Trabajas en varias sedes. Selecciona en la barra superior en cuál estás.') : __('No tienes ninguna sede activa. Pide a un responsable que te asigne una.')"
        />
    @else
        {{-- Print: the sheet alone — no chrome, no controls, the rows and the totals in black on white. --}}
        <style>
            @media print {
                [data-counter-topbar], [data-sheet-controls], [data-receipt-sheet], [data-counter-chrome] { display: none !important; }
                [data-today-sheet] { color: #0f172a !important; background: #ffffff !important; }
                [data-today-sheet] * { color: #0f172a !important; background: transparent !important; box-shadow: none !important; }
                [data-sheet-row] { break-inside: avoid; }
            }
        </style>

        <div data-today-sheet class="space-y-4">
            <section class="rounded-2xl border border-line bg-surface p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold">{{ __('Hoy') }} · {{ $day ? __(':weekday :day de :month', ['weekday' => $day->translatedFormat('l'), 'day' => $day->format('j'), 'month' => $day->translatedFormat('F')]) : '' }}</h2>
                        <p data-sheet-print class="text-sm text-ink-muted dark:text-slate-400">{{ $location?->name }} · {{ __('Para cuadrar con la hoja') }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2" data-sheet-controls>
                        <x-button variant="secondary" size="md" class="min-h-11" x-on:click="window.print()" data-sheet-print-button>{{ __('Imprimir') }}</x-button>
                        @if ($canExport)
                            <x-button variant="secondary" size="md" class="min-h-11" :href="route('counter.today.csv')" download data-sheet-csv>{{ __('Descargar (CSV)') }}</x-button>
                        @endif
                    </div>
                </div>

                {{-- Filters: Todo / Dispensario / Barra, whose sales, and a member. --}}
                <div class="mt-4 space-y-3" data-sheet-controls>
                    <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('Qué mostrar') }}">
                        @foreach (['all' => __('Todo'), 'dispensary' => __('Dispensario'), 'bar' => __('Barra')] as $key => $label)
                            <button type="button" wire:click="setSource('{{ $key }}')" data-sheet-source="{{ $key }}" aria-pressed="{{ $source === $key ? 'true' : 'false' }}"
                                    @class(['inline-flex min-h-11 items-center rounded-full border px-4 text-sm font-semibold transition', $chipOn => $source === $key, $chipOff => $source !== $key])>{{ $label }}</button>
                        @endforeach
                    </div>
                    <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('Quién atendió') }}">
                        <button type="button" wire:click="showOperator(null)" aria-pressed="{{ ! $mine && $operatorFilter === null ? 'true' : 'false' }}"
                                @class(['inline-flex min-h-11 items-center rounded-full border px-4 text-sm font-semibold transition', $chipOn => ! $mine && $operatorFilter === null, $chipOff => $mine || $operatorFilter !== null])>{{ __('Todos') }}</button>
                        <button type="button" wire:click="$set('mine', true)" data-sheet-mine aria-pressed="{{ $mine ? 'true' : 'false' }}"
                                @class(['inline-flex min-h-11 items-center rounded-full border px-4 text-sm font-semibold transition', $chipOn => $mine, $chipOff => ! $mine])>{{ __('Solo lo mío') }}</button>
                        @foreach ($operators as $person)
                            <button type="button" wire:click="showOperator('{{ $person['id'] }}')" aria-pressed="{{ ! $mine && $operatorFilter === $person['id'] ? 'true' : 'false' }}"
                                    @class(['inline-flex min-h-11 items-center rounded-full border px-4 text-sm font-semibold transition', $chipOn => ! $mine && $operatorFilter === $person['id'], $chipOff => $mine || $operatorFilter !== $person['id']])>{{ $person['name'] }}</button>
                        @endforeach
                    </div>
                    <input type="search" wire:model.live.debounce.300ms="memberFilter" autocomplete="off" data-sheet-member-filter
                           aria-label="{{ __('Buscar por socio (número o nombre)') }}" placeholder="{{ __('Buscar por socio (número o nombre)…') }}"
                           class="h-12 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 md:max-w-md">
                </div>
            </section>

            {{-- The rows. --}}
            <section class="rounded-2xl border border-line bg-surface shadow-sm dark:border-slate-800 dark:bg-slate-900" data-sheet-rows>
                @forelse ($rows as $row)
                    @php $tappable = $row['receipt_url'] !== null; @endphp
                    <div wire:key="sheet-{{ $row['id'] }}" data-sheet-row="{{ $row['kind'] }}" @if ($row['voided']) data-sheet-voided @endif
                         class="border-b border-line last:border-b-0 dark:border-slate-800">
                        <button type="button" @if ($tappable) x-on:click="$dispatch('counter-receipt-open', { url: @js($row['receipt_url']) })" @else disabled @endif
                                @class(['grid w-full grid-cols-[3.5rem_minmax(0,1fr)] gap-x-3 gap-y-1 px-4 py-3 text-left sm:grid-cols-[3.5rem_minmax(0,1fr)_auto] sm:px-5',
                                    'hover:bg-surface-alt dark:hover:bg-slate-800' => $tappable, 'cursor-default' => ! $tappable])>
                            <span class="pt-0.5 text-sm font-semibold tabular-nums">{{ $row['time'] }}</span>
                            <span class="min-w-0">
                                <span @class(['block text-sm font-semibold', 'line-through decoration-2 text-ink-muted dark:text-slate-500' => $row['voided']])>
                                    {{ $row['member_no'] !== '' ? $row['member_no'].' · '.$row['member_name'] : __('Sin socio') }}
                                    <span class="ml-1 rounded-full border border-line px-2 py-0.5 text-[11px] font-medium text-ink-muted no-underline dark:border-slate-700 dark:text-slate-400">{{ $row['kind'] === 'bar' ? __('Barra') : __('Dispensario') }}</span>
                                </span>
                                <span @class(['block text-sm', 'line-through text-ink-muted dark:text-slate-500' => $row['voided'], 'text-ink dark:text-slate-200' => ! $row['voided']])>{{ implode(' · ', $row['what']) }}</span>
                                <span class="block text-xs text-ink-muted dark:text-slate-400">
                                    {{ __('Atendió: :name', ['name' => $row['operator_name']]) }}
                                    @if ($row['voided'])
                                        · <span class="font-semibold text-error">{{ __('Anulada') }}</span>@if ($row['void_reason']): {{ $row['void_reason'] }}@endif
                                    @elseif ($row['refunded_cents'] > 0)
                                        · <span class="font-semibold text-warning">{{ __('Devuelta en parte') }}@if ($showMoney): {{ $money($row['refunded_cents']) }}@endif</span>
                                    @endif
                                </span>
                            </span>
                            @if ($showMoney)
                                <span data-sheet-money class="col-start-2 text-sm sm:col-start-auto sm:text-right">
                                    <span @class(['block font-semibold tabular-nums', 'line-through text-ink-muted' => $row['voided']])>{{ $money($row['money']['total']) }}</span>
                                    <span class="block text-xs text-ink-muted dark:text-slate-400">{{ collect(['cash' => __('efectivo'), 'wallet' => __('monedero'), 'tab' => __('cuenta')])->filter(fn ($l, $k) => $row['money'][$k] > 0)->map(fn ($l, $k) => $l.' '.$money($row['money'][$k]))->implode(' · ') ?: '—' }}</span>
                                </span>
                            @endif
                        </button>
                    </div>
                @empty
                    <p data-sheet-empty class="px-5 py-8 text-center text-sm text-ink-muted dark:text-slate-400">{{ __('Aún no hay operaciones hoy en esta sede.') }}</p>
                @endforelse
            </section>

            {{-- The totals a paper sheet has (voided sales never count). --}}
            @if ($totals)
                <section data-sheet-totals class="rounded-2xl border border-line bg-surface p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-5">
                    <h3 class="text-base font-semibold">{{ __('Totales del día') }}</h3>
                    <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                        <div class="flex justify-between gap-3"><dt class="text-ink-muted dark:text-slate-400">{{ __('Operaciones') }}</dt><dd data-sheet-count class="font-semibold tabular-nums">{{ $totals['count'] }} <span class="font-normal text-ink-muted">({{ __('dispensario :d · barra :b', ['d' => $totals['dispensations'], 'b' => $totals['orders']]) }})</span></dd></div>
                        @foreach ($totals['strains'] as $strain)
                            <div class="flex justify-between gap-3" data-sheet-strain-total>
                                <dt class="text-ink-muted dark:text-slate-400">{{ $strain['name'] }}</dt>
                                <dd class="font-semibold tabular-nums">{{ \App\Support\Weight::fromCentigrams($strain['grams_cg'])->formatted() }}@if ($strain['charged_cg'] !== $strain['grams_cg']) <span class="font-normal text-ink-muted">· {{ __('se cobra :grams', ['grams' => \App\Support\Weight::fromCentigrams($strain['charged_cg'])->formatted()]) }}</span>@endif</dd>
                            </div>
                        @endforeach
                        @if ($totals['strains'] !== [])
                            <div class="flex justify-between gap-3 border-t border-line pt-2 dark:border-slate-800"><dt class="font-medium">{{ __('Total dispensado') }}</dt><dd class="font-semibold tabular-nums">{{ \App\Support\Weight::fromCentigrams(array_sum(array_column($totals['strains'], 'grams_cg')))->formatted() }}</dd></div>
                        @endif
                        @foreach ($totals['units'] as $unit)
                            <div class="flex justify-between gap-3"><dt class="text-ink-muted dark:text-slate-400">{{ $unit['name'] }}</dt><dd class="font-semibold tabular-nums">{{ trans_choice(':count ud|:count uds', $unit['units'], ['count' => $unit['units']]) }}</dd></div>
                        @endforeach
                        <div class="flex justify-between gap-3"><dt class="text-ink-muted dark:text-slate-400">{{ __('Barra y tienda: productos') }}</dt><dd class="font-semibold tabular-nums">{{ $totals['bar_items'] }}</dd></div>
                        @if ($showMoney)
                            {{-- Prompt 374 — the two ledgers apart, never added: «Aportaciones» is the home panel's figure; «Barra y
                                 tienda» its own income. Each split by how it was paid. --}}
                            @php($split = fn (array $m): string => __('efectivo').' '.$money($m['cash']).' · '.__('monedero').' '.$money($m['wallet']).' · '.__('cuenta').' '.$money($m['tab']))
                            <div data-sheet-money data-sheet-contributions class="flex justify-between gap-3 border-t border-line pt-2 dark:border-slate-800 sm:col-span-2">
                                <dt class="font-medium">{{ __('Aportaciones') }}</dt>
                                <dd class="text-right font-semibold tabular-nums">{{ $money($totals['money']['dispensary']['total']) }}
                                    <span class="block text-xs font-normal text-ink-muted dark:text-slate-400">{{ $split($totals['money']['dispensary']) }}</span>
                                </dd>
                            </div>
                            <div data-sheet-bar-money class="flex justify-between gap-3 sm:col-span-2">
                                <dt class="font-medium">{{ __('Barra y tienda') }}</dt>
                                <dd class="text-right font-semibold tabular-nums">{{ $money($totals['money']['bar']['total']) }}
                                    <span class="block text-xs font-normal text-ink-muted dark:text-slate-400">{{ $split($totals['money']['bar']) }}</span>
                                </dd>
                            </div>
                            {{-- Its own boxes (373): where the day's cash went, as the close counts it. --}}
                            @if ($totals['boxes'] !== null)
                                <div data-sheet-boxes class="flex justify-between gap-3 border-t border-dashed border-line pt-2 text-xs dark:border-slate-800 sm:col-span-2">
                                    <dt class="font-medium text-ink-muted dark:text-slate-400">{{ __('Efectivo por bote') }}</dt>
                                    <dd class="text-right tabular-nums">{{ collect($totals['boxes'])->map(fn (int $cents, string $pot): string => \App\Enums\CashPot::from($pot)->boxTitle().': '.$money($cents))->implode(' · ') }}</dd>
                                </div>
                            @endif
                        @endif
                    </dl>
                </section>
            @endif
        </div>

        {{-- The one receipt sheet (252), opened by a row with its own URL. --}}
        <x-counter.receipt-sheet url="" :trigger="false" />
    @endif
    @endif
</div>
