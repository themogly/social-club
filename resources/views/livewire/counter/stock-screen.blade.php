{{-- Prompt 364 — «Existencias»: every jar and sealed top-up at the counter's sede. Tap a lote for its actions: Rellenar,
     Pasar a reserva, Actualizar peso del bote, Añadir a la reserva — each shown only to who may do it (and refused
     server-side otherwise). Stock work, beside selling, never inside it. --}}
<div>
    @include('livewire.counter.partials.counter-surface')

    @if (! $this->handoverActive())
    @php
        $blocker = \App\Support\CounterBlocker::first([
            \App\Support\CounterBlocker::SEDE => ! $noLocation,
            \App\Support\CounterBlocker::OPERATOR => $this->hasOperator(),
        ]);
    @endphp

    @if (\App\Support\CounterBlocker::rendersInPage($blocker))
        <x-counter.blocking-state
            data-blocker="sede"
            icon="map-pin"
            :heading="$mustChooseLocation ? __('Elige tu sede') : __('Sin sede asignada')"
            :body="$mustChooseLocation ? __('Trabajas en varias sedes. Selecciona en la barra superior en cuál estás.') : __('No tienes ninguna sede activa. Pide a un responsable que te asigne una.')"
        />
    @else
        @if ($from === 'pos')
            {{-- Back to the sale as it was: the basket and the held socio survive the trip (205, 364). --}}
            <div class="mb-4">
                <a href="{{ route('counter.pos', ['volver' => 1]) }}" data-back-to-pos
                   class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-line bg-surface px-4 text-sm font-semibold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800">
                    <span aria-hidden="true">←</span> {{ __('Volver al dispensario') }}
                </a>
            </div>
        @endif

        <div @class(['grid gap-5', 'lg:grid-cols-[minmax(0,1fr)_26rem]' => $open !== null])>
            {{-- ============ The list ============ --}}
            <section data-stock-list class="min-w-0 rounded-2xl border border-line bg-surface p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-5">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <h2 class="text-lg font-semibold">{{ __('Existencias') }}</h2>
                    @if ($summary)
                        <p data-stock-summary class="text-sm font-medium text-ink-muted dark:text-slate-400">{{ $summary['text'] }}</p>
                    @endif
                </div>

                {{-- Prompt 365 — the search box has its OWN full-width row on a phone (in one wrapping row with the chips it shrank
                     to a sliver: «Se»), and keeps ≥ 14rem beside the chips from 768 px. The chips wrap underneath. --}}
                <div class="mt-3 flex flex-col gap-2 md:flex-row md:flex-wrap md:items-center">
                    <label class="sr-only" for="stock-search">{{ __('Buscar variedad o lote') }}</label>
                    <input id="stock-search" type="search" wire:model.live.debounce.300ms="batchFilter" autocomplete="off" data-stock-search
                           placeholder="{{ __('Buscar variedad o lote…') }}"
                           class="h-12 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 md:w-auto md:min-w-56 md:flex-1">
                    <div class="flex flex-wrap items-center gap-2" data-stock-filters>
                        @foreach (['all' => __('Todos'), 'reserve' => __('Con reserva'), 'low' => __('Bote bajo')] as $key => $label)
                            <button type="button" wire:click="setFilter('{{ $key }}')" data-stock-filter="{{ $key }}" aria-pressed="{{ $filter === $key ? 'true' : 'false' }}"
                                    @class(['inline-flex min-h-11 items-center rounded-full border px-4 text-sm font-semibold transition',
                                        'border-brand bg-brand text-white' => $filter === $key,
                                        'border-line bg-surface text-ink-muted hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-300' => $filter !== $key])>{{ $label }}</button>
                        @endforeach
                    </div>
                </div>

                <div class="counter-scroll-region mt-4 max-h-[calc(100dvh-16rem)] overflow-y-auto overscroll-contain">
                    @php
                        $mainRows = $rows->where('empty', false);
                        $emptyRows = $rows->where('empty', true);
                    @endphp
                    @foreach ($mainRows as $row)
                        @include('livewire.counter.partials.stock-row')
                    @endforeach

                    {{-- Prompt 365 — the batches with nothing in the jar or the reserve are COUNTED, not a silent checkbox («the show empty
                         isn't working»: they appeared A–Z far down a long list). Shown, they are grouped at the bottom. --}}
                    @if ($emptyCount > 0)
                        <button type="button" wire:click="toggleEmpty" data-stock-empty-toggle
                                class="mb-2 inline-flex min-h-11 items-center gap-1 rounded-lg px-2 text-sm text-ink-muted hover:text-ink dark:text-slate-400 dark:hover:text-slate-200">
                            @if ($showEmpty)
                                {{ __('Ocultar agotados') }}
                            @else
                                {{ trans_choice(':count lote agotado oculto|:count lotes agotados ocultos', $emptyCount, ['count' => $emptyCount]) }} · <span class="font-semibold text-brand dark:text-slate-200">{{ __('Mostrar') }}</span>
                            @endif
                        </button>
                    @endif
                    @if ($showEmpty && $emptyRows->isNotEmpty())
                        <h3 class="mb-2 mt-2 text-xs font-semibold uppercase tracking-wide text-ink-muted dark:text-slate-400" data-stock-empty-heading>{{ __('Agotados') }}</h3>
                        @foreach ($emptyRows as $row)
                            @include('livewire.counter.partials.stock-row')
                        @endforeach
                    @endif

                    @if ($mainRows->isEmpty() && ! ($showEmpty && $emptyRows->isNotEmpty()))
                        <div class="rounded-xl border border-dashed border-line px-4 py-8 text-center dark:border-slate-700" data-stock-empty>
                            @if ($filter === 'reserve' && $batchFilter === '' && ($summary['reserve_cg'] ?? 0) === 0)
                                {{-- Prompt 365 — an empty reserve is data, not a typing problem: say why, and how it gets there. --}}
                                <p class="font-medium text-ink dark:text-slate-100" data-stock-no-reserve>{{ __('No hay reserva sellada apuntada en esta sede.') }}</p>
                                <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">{{ __('Se apunta al recibir un lote («De ello, en reserva»), con «Pasar a reserva» aquí, o en Inventario.') }}</p>
                            @elseif ($batchFilter !== '' || $filter !== 'all')
                                <p class="font-medium text-ink dark:text-slate-100">{{ __('Nada coincide.') }}</p>
                                <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">{{ __('Prueba con otro nombre o quita el filtro.') }}</p>
                            @else
                                <p class="font-medium text-ink dark:text-slate-100">{{ __('No hay existencias en esta sede.') }}</p>
                                <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">{{ __('Los lotes aparecen aquí al darles entrada en el panel.') }}</p>
                            @endif
                        </div>
                    @endif
                </div>
            </section>

            {{-- ============ One lote's actions ============ --}}
            @if ($open !== null)
                {{-- Portrait tablets: the panel comes FIRST and scrolls into view, so a tapped row's actions are not under the list. --}}
                {{-- Prompt 368 — ACTION FIRST, then the amount (Ben's iPhone: one pad fed four actions and nothing said which). The
                     panel opens on the figures and the actions this person can do on THIS lote; tapping one shows only its controls:
                     the pad, its one-tap (Toda la reserva), its reason where it needs one, and ONE confirm button whose label is the
                     result («Rellenar 10.00 g → bote 12.80 g»). An action that cannot apply is not offered (no reserve → no
                     Rellenar; an empty jar → no Pasar a reserva). The figures ride as data-* so the labels follow a fresh render. --}}
                @php
                    $fmt = fn (int $n): string => $open['is_unit'] ? \App\Support\Units::count($n) : \App\Support\Weight::fromCentigrams($n)->formatted();
                    $actions = array_filter([
                        'top-up' => $canTopUp && ! $open['is_unit'] && $open['reserve_cg'] > 0 ? __('Rellenar el bote') : null,
                        'move' => $canTopUp && ! $open['is_unit'] && $open['jar'] > 0 ? __('Pasar a reserva') : null,
                        'weigh' => $canWeigh ? ($open['is_unit'] ? __('Corregir unidades del bote') : __('Corregir peso del bote')) : null,
                        'add-reserve' => $canAddReserve && ! $open['is_unit'] ? __('Añadir a la reserva') : null,
                    ]);
                    $labels = [
                        'top-up' => __('Rellenar :amount → bote :jar'),
                        'move' => __('Pasar :amount a reserva → bote :jar'),
                        'weigh' => __('Corregir a :amount (:diff)'),
                        'add-reserve' => __('Añadir :amount a la reserva'),
                    ];
                @endphp
                <section data-stock-panel="{{ $open['id'] }}" wire:key="panel-{{ $open['id'] }}"
                         data-jar="{{ $open['jar'] }}" data-reserve="{{ $open['reserve_cg'] }}" data-unit="{{ $open['is_unit'] ? '1' : '0' }}"
                         x-init="if (window.matchMedia('(max-width: 1023px)').matches) $nextTick(() => $el.scrollIntoView({ block: 'start' }))"
                         x-data="window.stockActionPanel(@js(['labels' => $labels, 'unitWord' => [__(':count ud'), __(':count uds')]]))"
                         class="order-first min-w-0 rounded-2xl border border-brand/40 bg-surface p-4 shadow-sm dark:bg-slate-900 sm:p-5 lg:order-none lg:sticky lg:top-4 lg:self-start">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h3 class="text-lg font-semibold">{{ $open['name'] }}</h3>
                            <p class="text-xs text-ink-muted dark:text-slate-400">{{ $open['subtitle'] }}</p>
                        </div>
                        <button type="button" wire:click="closeBatch" data-stock-close class="inline-flex min-h-11 shrink-0 items-center rounded-lg px-3 text-sm font-medium text-ink-muted hover:text-ink dark:text-slate-400">{{ __('Cerrar') }}</button>
                    </div>
                    <p class="mt-2 text-sm tabular-nums" data-stock-panel-figures>
                        {{ __('En el bote') }} <strong>{{ $open['jar_text'] }}</strong>
                        @unless ($open['is_unit'])
                            · @if ($open['reserve_cg'] > 0) {{ __('Reserva') }} <strong>{{ $open['reserve_text'] }}</strong> @else <span data-no-reserve>{{ __('Sin reserva sellada') }}</span> @endif
                        @endunless
                    </p>

                    @if ($confirmation)
                        <p role="status" data-stock-confirmation class="mt-3 rounded-xl border border-success/40 bg-success/10 px-3 py-2 text-sm font-medium text-success">{{ $confirmation }}</p>
                    @endif
                    @include('livewire.counter.partials.counter-flash', ['anchor' => 'data-stock-feedback', 'spacing' => 'mt-3'])

                    {{-- 1. The actions this person can do on this lote. --}}
                    <div x-show="action === null" class="mt-4 space-y-2" data-stock-actions>
                        @forelse ($actions as $key => $label)
                            <button type="button" x-on:click="choose('{{ $key }}')" data-action-{{ $key }} data-action-choose="{{ $key }}"
                                    class="flex min-h-12 w-full items-center justify-between gap-3 rounded-xl border border-line bg-surface px-4 py-2 text-left text-base font-semibold text-ink transition hover:border-brand hover:bg-brand-tint dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:hover:bg-slate-800">
                                <span class="min-w-0 break-words" data-action-label>{{ $label }}</span>
                                <span aria-hidden="true" class="shrink-0 text-ink-muted dark:text-slate-400">›</span>
                            </button>
                        @empty
                            <p class="rounded-xl border border-dashed border-line px-4 py-3 text-sm text-ink-muted dark:border-slate-700 dark:text-slate-400" data-stock-no-actions>{{ __('No hay nada que puedas hacer con este lote.') }}</p>
                        @endforelse
                    </div>

                    {{-- 2. One action: its pad, its own controls and one confirm button that says the result. --}}
                    <div x-show="action !== null" x-cloak class="mt-4" data-stock-action-open>
                        <div class="flex items-center justify-between gap-2">
                            <button type="button" x-on:click="back()" data-action-back class="inline-flex min-h-11 items-center rounded-lg pr-3 text-sm font-medium text-brand dark:text-slate-200">← {{ __('Otra acción') }}</button>
                            @foreach ($actions as $key => $label)
                                <p x-show="action === '{{ $key }}'" class="min-w-0 text-right text-sm font-semibold">{{ $label }}</p>
                            @endforeach
                        </div>
                        <p x-show="action === 'weigh'" class="mt-1 text-xs text-ink-muted dark:text-slate-400">{{ $open['is_unit'] ? __('Cuenta las unidades y escríbelas en el teclado.') : __('Pesa el bote y escribe lo que marca la báscula. El sistema calcula la diferencia.') }}</p>
                        <p x-show="action === 'add-reserve'" class="mt-1 text-xs text-ink-muted dark:text-slate-400">{{ __('Bolsas selladas que llegaron y no se dieron de entrada. Queda registrado con su motivo.') }}</p>

                        <div class="mt-3 flex h-14 items-center justify-between rounded-xl border border-line bg-surface-alt px-4 dark:border-slate-700 dark:bg-slate-950">
                            <span class="text-sm text-ink-muted dark:text-slate-400">{{ $open['is_unit'] ? __('Unidades') : __('Gramos') }}</span>
                            <span class="text-2xl font-bold tabular-nums" data-stock-pad-value x-text="(value || '0') + @js($open['is_unit'] ? '' : ' g')"></span>
                        </div>
                        <div class="mt-2 grid grid-cols-3 gap-2" data-stock-pad>
                            @foreach (['1','2','3','4','5','6','7','8','9'] as $digit)
                                <button type="button" @click="push('{{ $digit }}')" class="h-12 rounded-xl border border-line bg-surface text-xl font-semibold text-ink hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">{{ $digit }}</button>
                            @endforeach
                            @if ($open['is_unit'])
                                <span></span>
                            @else
                                <button type="button" @click="push('.')" aria-label="{{ __('Punto decimal') }}" class="h-12 rounded-xl border border-line bg-surface text-xl font-semibold text-ink hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">.</button>
                            @endif
                            <button type="button" @click="push('0')" class="h-12 rounded-xl border border-line bg-surface text-xl font-semibold text-ink hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">0</button>
                            <button type="button" @click="erase()" aria-label="{{ __('Retroceso') }}" class="h-12 rounded-xl border border-line bg-surface text-xl font-semibold text-ink-muted hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-400">⌫</button>
                        </div>

                        <div class="mt-3 space-y-2">
                            @if (isset($actions['top-up']))
                                <div x-show="action === 'top-up'" class="space-y-2">
                                    <x-button size="wrap" variant="secondary" class="w-full" data-action-top-up-all wire:loading.attr="disabled"
                                              x-on:click="run($wire.topUpJar(null))">{{ __('Toda la reserva (:grams)', ['grams' => $open['reserve_text']]) }}</x-button>
                                    <x-button size="wrap" class="w-full" data-action-confirm="top-up" x-bind:disabled="cg === null" wire:loading.attr="disabled"
                                              x-on:click="cg !== null && run($wire.topUpJar(value))"><span x-text="label('top-up')"></span></x-button>
                                </div>
                            @endif
                            @if (isset($actions['move']))
                                <x-button size="wrap" class="w-full" x-show="action === 'move'" data-action-confirm="move" x-bind:disabled="cg === null" wire:loading.attr="disabled"
                                          x-on:click="cg !== null && run($wire.moveToReserve(value))"><span x-text="label('move')"></span></x-button>
                            @endif
                            @if (isset($actions['weigh']))
                                <div x-show="action === 'weigh'" class="space-y-2">
                                    @unless ($reasonOptional)
                                        <div data-weigh-reasons class="space-y-2">
                                            <p class="text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('¿Qué ha pasado?') }}</p>
                                            <div class="grid gap-2 sm:grid-cols-2">
                                                @foreach ($this->reasonPicks() as $pick)
                                                    <button type="button" data-weigh-reason="{{ $pick->value }}" x-on:click="reason = '{{ $pick->value }}'"
                                                            x-bind:aria-pressed="reason === '{{ $pick->value }}' ? 'true' : 'false'"
                                                            x-bind:class="reason === '{{ $pick->value }}' ? 'border-brand bg-brand-tint text-brand dark:bg-slate-800 dark:text-slate-100' : 'border-line text-ink dark:border-slate-700 dark:text-slate-100'"
                                                            class="min-h-11 w-full rounded-xl border px-3 py-2 text-sm font-semibold">{{ $pick->label() }}</button>
                                                @endforeach
                                            </div>
                                            <input type="text" x-model="other" x-on:input="reason = other.trim() === '' ? (reason === 'OTHER' ? null : reason) : 'OTHER'" maxlength="120"
                                                   aria-label="{{ __('Otro motivo') }}" placeholder="{{ __('Otro motivo…') }}" data-weigh-reason-other
                                                   class="h-11 w-full rounded-xl border border-line bg-surface px-3 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                                        </div>
                                    @endunless
                                    <x-button size="wrap" class="w-full" data-action-confirm="weigh" wire:loading.attr="disabled"
                                              x-bind:disabled="cg === null || {{ $reasonOptional ? 'false' : 'reason === null' }}"
                                              x-on:click="cg !== null && run({{ $reasonOptional ? '$wire.updateJarWeight(value)' : '$wire.updateJarWeight(value, reason, other)' }})"><span x-text="label('weigh')"></span></x-button>
                                </div>
                            @endif
                            @if (isset($actions['add-reserve']))
                                <div x-show="action === 'add-reserve'" class="space-y-2">
                                    <input type="text" x-model="addReason" maxlength="200" aria-label="{{ __('Motivo') }}" placeholder="{{ __('Motivo (obligatorio)') }}" data-add-reserve-reason
                                           class="h-11 w-full rounded-xl border border-line bg-surface px-3 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                                    <x-button size="wrap" class="w-full" data-action-confirm="add-reserve" wire:loading.attr="disabled"
                                              x-bind:disabled="cg === null || addReason.trim() === ''"
                                              x-on:click="cg !== null && run($wire.addToReserve(value, addReason))"><span x-text="label('add-reserve')"></span></x-button>
                                </div>
                            @endif
                        </div>
                    </div>
                </section>
            @endif
        </div>
    @endif
    @endif
</div>
