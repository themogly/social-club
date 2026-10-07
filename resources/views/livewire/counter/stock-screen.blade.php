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
                <section data-stock-panel="{{ $open['id'] }}" wire:key="panel-{{ $open['id'] }}"
                         x-init="if (window.matchMedia('(max-width: 1023px)').matches) $nextTick(() => $el.scrollIntoView({ block: 'start' }))"
                         x-data="{ value: '', push(d) { if (d === '.' && this.value.includes('.')) return; if (this.value.length < 7) this.value += d }, back() { this.value = this.value.slice(0, -1) }, reasons: false, other: '', addReason: '' }"
                         class="order-first rounded-2xl border border-brand/40 bg-surface p-4 shadow-sm dark:bg-slate-900 sm:p-5 lg:order-none lg:sticky lg:top-4 lg:self-start">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h3 class="text-lg font-semibold">{{ $open['name'] }}</h3>
                            <p class="text-xs text-ink-muted dark:text-slate-400">{{ $open['subtitle'] }}</p>
                        </div>
                        <button type="button" wire:click="closeBatch" data-stock-close class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-medium text-ink-muted hover:text-ink dark:text-slate-400">{{ __('Cerrar') }}</button>
                    </div>
                    <p class="mt-2 text-sm tabular-nums" data-stock-panel-figures>
                        {{ __('En el bote') }} <strong>{{ $open['jar_text'] }}</strong>
                        @unless ($open['is_unit']) · {{ __('Reserva') }} <strong>{{ $open['reserve_text'] }}</strong>@endunless
                    </p>

                    @if ($confirmation)
                        <p role="status" data-stock-confirmation class="mt-3 rounded-xl border border-success/40 bg-success/10 px-3 py-2 text-sm font-medium text-success">{{ $confirmation }}</p>
                    @endif
                    @include('livewire.counter.partials.counter-flash', ['anchor' => 'data-stock-feedback', 'spacing' => 'mt-3'])

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
                        <button type="button" @click="back()" aria-label="{{ __('Retroceso') }}" class="h-12 rounded-xl border border-line bg-surface text-xl font-semibold text-ink-muted hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-400">⌫</button>
                    </div>

                    <div class="mt-4 space-y-3">
                        @if ($canTopUp && ! $open['is_unit'])
                            <div class="flex flex-wrap gap-2" data-action-top-up>
                                <x-button size="md" class="min-h-11 flex-1" data-action-top-up-grams :disabled="$open['reserve_cg'] <= 0"
                                          x-on:click="value !== '' && $wire.topUpJar(value).then(() => value = '')" wire:loading.attr="disabled">{{ __('Rellenar') }}</x-button>
                                <x-button size="md" variant="secondary" class="min-h-11 flex-1" data-action-top-up-all :disabled="$open['reserve_cg'] <= 0"
                                          x-on:click="$wire.topUpJar(null).then(() => value = '')" wire:loading.attr="disabled">{{ __('Toda la reserva') }}</x-button>
                                <x-button size="md" variant="secondary" class="min-h-11 flex-1" data-action-move :disabled="$open['jar'] <= 0"
                                          x-on:click="value !== '' && $wire.moveToReserve(value).then(() => value = '')" wire:loading.attr="disabled">{{ __('Pasar a reserva') }}</x-button>
                            </div>
                        @endif

                        @if ($canWeigh)
                            <div data-action-weigh class="rounded-xl border border-line p-3 dark:border-slate-700">
                                <p class="text-sm font-medium">{{ __('Actualizar peso del bote') }}</p>
                                <p class="text-xs text-ink-muted dark:text-slate-400">{{ $open['is_unit'] ? __('Cuenta las unidades y escríbelas en el teclado.') : __('Pesa el bote y escribe lo que marca la báscula. El sistema calcula la diferencia.') }}</p>
                                @if ($reasonOptional)
                                    <x-button size="md" class="mt-2 min-h-11 w-full" data-action-weigh-save
                                              x-on:click="value !== '' && $wire.updateJarWeight(value).then(() => value = '')" wire:loading.attr="disabled">{{ __('Guardar peso') }}</x-button>
                                @else
                                    <x-button size="md" class="mt-2 min-h-11 w-full" x-show="! reasons" x-on:click="value !== '' && (reasons = true)">{{ __('Guardar peso') }}</x-button>
                                    <div x-show="reasons" x-cloak data-weigh-reasons class="mt-2 space-y-2">
                                        <p class="text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('¿Qué ha pasado?') }}</p>
                                        <div class="grid gap-2 sm:grid-cols-2">
                                            @foreach ($this->reasonPicks() as $pick)
                                                <x-button size="md" variant="secondary" class="min-h-11" data-weigh-reason="{{ $pick->value }}" wire:loading.attr="disabled"
                                                          x-on:click="$wire.updateJarWeight(value, '{{ $pick->value }}').then(() => { value = ''; reasons = false })">{{ $pick->label() }}</x-button>
                                            @endforeach
                                        </div>
                                        <div class="flex gap-2">
                                            <input type="text" x-model="other" maxlength="120" aria-label="{{ __('Otro motivo') }}" placeholder="{{ __('Otro motivo…') }}"
                                                   class="h-11 min-w-0 flex-1 rounded-xl border border-line bg-surface px-3 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                                            <x-button size="md" variant="secondary" class="min-h-11" data-weigh-reason="OTHER" x-bind:disabled="other.trim() === ''" wire:loading.attr="disabled"
                                                      x-on:click="$wire.updateJarWeight(value, 'OTHER', other).then(() => { value = ''; reasons = false; other = '' })">{{ __('Otro') }}</x-button>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endif

                        @if ($canAddReserve && ! $open['is_unit'])
                            <div data-action-add-reserve class="rounded-xl border border-line p-3 dark:border-slate-700">
                                <p class="text-sm font-medium">{{ __('Añadir a la reserva') }}</p>
                                <p class="text-xs text-ink-muted dark:text-slate-400">{{ __('Bolsas selladas que llegaron y no se dieron de entrada. Queda registrado con su motivo.') }}</p>
                                <div class="mt-2 flex gap-2">
                                    <input type="text" x-model="addReason" maxlength="200" aria-label="{{ __('Motivo') }}" placeholder="{{ __('Motivo (obligatorio)') }}"
                                           class="h-11 min-w-0 flex-1 rounded-xl border border-line bg-surface px-3 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                                    <x-button size="md" variant="secondary" class="min-h-11" x-bind:disabled="value === '' || addReason.trim() === ''" wire:loading.attr="disabled"
                                              x-on:click="$wire.addToReserve(value, addReason).then(() => { value = ''; addReason = '' })">{{ __('Añadir') }}</x-button>
                                </div>
                            </div>
                        @endif
                    </div>
                </section>
            @endif
        </div>
    @endif
    @endif
</div>
