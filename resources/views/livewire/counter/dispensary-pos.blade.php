{{-- Dispensary POS — tablet-first, dark-mode first-class. Three panels: the socio
     (left), the genetics grid (centre) and the basket + contribution (right). Every
     figure is live (limits, stock, prices, balances). A THIN shell: CommitDispensation
     is the compliance boundary — this screen only assembles the call. Fail-closed
     offline: the commit is disabled and the basket preserved until reconnection. --}}
<div
    x-data="{
        online: true,
        init() {
            this.online = navigator.onLine;
            window.addEventListener('online', () => { this.online = true; $wire.set('offline', false); });
            window.addEventListener('offline', () => { this.online = false; $wire.set('offline', true); });
            if (! this.online) { $wire.set('offline', true); }
            this.$watch(() => JSON.stringify($wire.basket), value => { try { localStorage.setItem('pos.basket', value); } catch (e) {} });
        },
    }"
>
    @include('livewire.counter.partials.operator-strip')

    @if ($noLocation)
        {{-- Intentional empty state: an operator with no assigned sede. Still a 200. --}}
        <div class="rounded-2xl border border-line bg-surface p-8 text-center dark:border-slate-800 dark:bg-slate-900">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-surface-alt text-2xl dark:bg-slate-800">📍</div>
            <h2 class="mt-4 text-lg font-semibold">{{ $mustChooseLocation ? __('Elige tu sede') : __('Sin sede asignada') }}</h2>
            <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">
                {{ $mustChooseLocation ? __('Trabajas en varias sedes. Selecciona en la barra superior en cuál estás.') : __('No tienes ninguna sede activa. Pide a un responsable que te asigne una para dispensar.') }}
            </p>
        </div>
    @else
        {{-- Offline banner — unmistakable, fail closed. --}}
        <div x-show="! online" x-cloak role="alert" aria-live="assertive" class="mb-4 flex items-center gap-3 rounded-xl border border-error/40 bg-error/10 px-4 py-3 text-sm font-semibold text-error">
            <span class="text-lg">⚠️</span>
            <span>{{ __('Sin conexión. No se puede registrar ninguna dispensación; la cesta se conserva y se reactivará al reconectar.') }}</span>
        </div>

        {{-- Flash --}}
        @if ($flashMessage)
            <div
                wire:key="flash"
                role="{{ $flashType === 'error' ? 'alert' : 'status' }}"
                aria-live="{{ $flashType === 'error' ? 'assertive' : 'polite' }}"
                @class([
                    'mb-4 flex items-center justify-between gap-3 rounded-xl border px-4 py-3 text-sm font-medium',
                    'border-success/30 bg-success/10 text-success' => $flashType === 'success',
                    'border-warning/30 bg-warning/10 text-warning' => $flashType === 'warning',
                    'border-error/30 bg-error/10 text-error' => $flashType === 'error',
                ])
            >
                <span>{{ $flashMessage }}</span>
                <button type="button" wire:click="$set('flashMessage', null)" aria-label="{{ __('Descartar aviso') }}" class="shrink-0 rounded-md px-2 py-1 opacity-70 hover:opacity-100">✕</button>
            </div>
        @endif

        {{-- Prompt 91 — the SAME basket-column pattern batch 2 gave the bar POS (the dispensary was never
             given it): at lg (1024, the counter's tablet-first width) the basket + contribution (RIGHT) is
             pinned to a dedicated column 2 spanning both rows, so socio (LEFT) + genetics (CENTRE) stack in
             column 1 with no dead space and the primary action stays top-right. At xl the RIGHT div resets
             to auto-placement for the 3-column sidebar layout. Kept identical to bar-pos on purpose — one
             layout, asserted by both PosLayout tests. --}}
        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start xl:grid-cols-[19rem_minmax(0,1fr)_21rem]">

            {{-- ================= LEFT: the socio ================= --}}
            <div class="flex flex-col gap-4">
                {{-- Identify --}}
                <section class="rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900">
                    <form wire:submit="submitScan">
                        <label for="scan" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Escanear tarjeta o buscar socio') }}</label>
                        <input
                            id="scan"
                            type="text"
                            wire:model="scan"
                            autofocus
                            autocomplete="off"
                            spellcheck="false"
                            placeholder="{{ __('Escanea la tarjeta, o escribe nombre / nº y pulsa Enter') }}"
                            class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        >
                    </form>

                    @if ($cameraScanEnabled)
                        <x-counter.camera-scan />
                    @endif

                    <div class="mt-3">
                        <label for="member-search" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('o busca por nombre / nº de socio') }}</label>
                        <input
                            id="member-search"
                            type="text"
                            wire:model.live.debounce.300ms="search"
                            autocomplete="off"
                            placeholder="{{ __('Ej. García o M-00042') }}"
                            class="mt-2 h-11 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        >

                        @if ($searchResults !== null)
                            <ul class="mt-2 divide-y divide-line overflow-hidden rounded-xl border border-line dark:divide-slate-800 dark:border-slate-800">
                                @forelse ($searchResults as $result)
                                    <li>
                                        <button type="button" wire:click="selectMember('{{ $result->id }}')" class="flex w-full items-center justify-between gap-3 bg-surface px-4 py-3 text-left transition hover:bg-surface-alt dark:bg-slate-900 dark:hover:bg-slate-800">
                                            <span class="font-medium">{{ $result->fullName() }}</span>
                                            <span class="text-sm text-ink-muted dark:text-slate-400">{{ $result->member_no }}</span>
                                        </button>
                                    </li>
                                @empty
                                    <li class="bg-surface px-4 py-3 text-sm text-ink-muted dark:bg-slate-900 dark:text-slate-400">{{ __('Sin resultados.') }}</li>
                                @endforelse
                            </ul>
                        @endif

                        @if ($requireCheckedIn)
                            <p class="mt-2 text-xs text-ink-muted dark:text-slate-500">{{ __('Esta sede solo permite dispensar a socios que han registrado su entrada.') }}</p>
                        @endif
                    </div>
                </section>

                {{-- Member card OR prompt --}}
                @if ($member)
                    @php
                        $inCarencia = $member->carencia_ends_at !== null && $member->carencia_ends_at->isFuture();
                        $statusColour = match ($member->status) {
                            \App\Enums\MemberStatus::ACTIVE => 'border-success/30 bg-success/10 text-success',
                            \App\Enums\MemberStatus::APPLICANT => 'border-warning/30 bg-warning/10 text-warning',
                            \App\Enums\MemberStatus::SUSPENDED, \App\Enums\MemberStatus::EXPELLED => 'border-error/30 bg-error/10 text-error',
                            default => 'border-line bg-surface-alt text-ink-muted dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300',
                        };
                    @endphp

                    <section class="rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900">
                        <div class="flex items-start gap-3">
                            @if ($photoUrl)
                                <img src="{{ $photoUrl }}" alt="" class="h-20 w-20 shrink-0 rounded-2xl object-cover">
                            @else
                                <div class="flex h-20 w-20 shrink-0 items-center justify-center rounded-2xl bg-brand-tint text-2xl font-bold text-brand dark:bg-slate-800 dark:text-slate-200">
                                    {{ mb_strtoupper(mb_substr($member->first_name, 0, 1).mb_substr($member->last_name, 0, 1)) }}
                                </div>
                            @endif

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="truncate text-lg font-bold">{{ $member->fullName() }}</h2>
                                    <span class="rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $statusColour }}">{{ $member->status->label() }}</span>
                                </div>
                                <p class="mt-0.5 text-sm text-ink-muted dark:text-slate-400">{{ $member->member_no }}</p>
                                <p class="mt-1 text-sm">
                                    <span class="text-ink-muted dark:text-slate-400">{{ __('Cuota / tier') }}:</span>
                                    <span class="font-medium">{{ $membership?->tier?->name ?? '—' }}</span>
                                </p>
                            </div>

                            <button type="button" wire:click="clearMember" class="shrink-0 rounded-lg px-2 py-1.5 text-sm text-ink-muted transition hover:bg-black/5 dark:text-slate-400 dark:hover:bg-white/5">{{ __('Cerrar') }}</button>
                        </div>

                        {{-- Wallet + carencia --}}
                        <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm">
                            <div>
                                <dt class="text-ink-muted dark:text-slate-400">{{ __('Monedero') }}</dt>
                                <dd class="font-semibold {{ $walletCents < 0 ? 'text-error' : '' }}">{{ $this->money($walletCents) }}</dd>
                            </div>
                            <div>
                                <dt class="text-ink-muted dark:text-slate-400">{{ __('Carencia') }}</dt>
                                <dd class="font-medium">
                                    @if ($inCarencia)
                                        <span class="text-warning">{{ __('Hasta') }} {{ $member->carencia_ends_at->format('d/m/Y') }}</span>
                                    @else
                                        {{ __('Cumplida') }}
                                    @endif
                                </dd>
                            </div>
                        </dl>

                        {{-- Consumption gauge (MTD grams / monthly limit) — colour + numbers, never colour alone. --}}
                        @if ($limits)
                            @php
                                $pct = $limits->monthlyPercent();
                                $gaugeState = $limits->gaugeState();
                                $gaugeBar = match ($gaugeState) { 'alert' => 'bg-error', 'warning' => 'bg-warning', default => 'bg-success' };
                                $gaugeText = match ($gaugeState) { 'alert' => 'text-error', 'warning' => 'text-warning', default => 'text-success' };
                            @endphp
                            <div class="mt-4">
                                <div class="flex items-baseline justify-between text-sm">
                                    <span class="font-medium">{{ __('Consumo del mes') }}</span>
                                    <span class="font-semibold {{ $gaugeText }}">{{ $this->grams($limits->monthlyUsedCg) }} / {{ $this->grams($limits->monthlyLimitCg) }} · {{ $pct }}%</span>
                                </div>
                                <div class="mt-1.5 h-3 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                                    <div class="h-full rounded-full {{ $gaugeBar }}" style="width: {{ min($pct, 100) }}%"></div>
                                </div>
                                <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">
                                    {{ __('Hoy') }}: {{ $this->grams($limits->dailyUsedCg) }} / {{ $this->grams($limits->dailyLimitCg) }}
                                    · {{ __('Restante hoy') }}: <span class="font-medium">{{ $this->grams($limits->dailyRemainingCg()) }}</span>
                                </p>
                            </div>
                        @endif

                        {{-- Active sanction --}}
                        @if ($sanction)
                            <div class="mt-3 rounded-xl border border-error/30 bg-error/10 px-3 py-2 text-sm text-error">
                                <p class="font-semibold">{{ __('Sanción activa') }} · {{ __($sanction->type->value) }}</p>
                                @if ($sanction->reason)<p class="mt-0.5">{{ $sanction->reason }}</p>@endif
                            </div>
                        @endif

                        {{-- Counter verdict (same shared resolver as the door). --}}
                        @if ($verdict)
                            <div class="mt-4 border-t border-line pt-3 dark:border-slate-800">
                                @if ($verdict->isClear())
                                    <div class="flex items-center gap-2 rounded-xl border border-success/30 bg-success/10 px-3 py-2 text-sm font-semibold text-success">
                                        <span>✓</span><span>{{ __('Apto para dispensar.') }}</span>
                                    </div>
                                @else
                                    <div class="space-y-2">
                                        @foreach ($verdict->rules as $rule)
                                            @continue($rule['satisfied'])
                                            @php $isBlock = in_array($rule['mode'], ['BLOCK', 'OVERRIDE'], true); @endphp
                                            <div @class([
                                                'flex items-center justify-between gap-3 rounded-xl border px-3 py-2 text-sm',
                                                'border-error/30 bg-error/10 text-error' => $isBlock,
                                                'border-warning/30 bg-warning/10 text-warning' => ! $isBlock,
                                            ])>
                                                <span>{{ $rule['message'] }}</span>
                                                <span class="shrink-0 rounded-full border border-current px-2 py-0.5 text-[10px] font-semibold uppercase">{{ $isBlock ? __('Bloquea') : __('Aviso') }}</span>
                                            </div>
                                        @endforeach
                                        @if (! empty($hardBlockRules))
                                            <p class="text-sm font-medium text-error">{{ __('No se puede dispensar a este socio.') }}</p>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endif
                    </section>
                @else
                    <div class="rounded-2xl border border-dashed border-line bg-surface p-8 text-center dark:border-slate-700 dark:bg-slate-900">
                        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-surface-alt text-xl dark:bg-slate-800">🪪</div>
                        <p class="mt-3 font-medium">{{ __('Identifica a un socio') }}</p>
                        <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">{{ __('Sin socio no se puede registrar ninguna dispensación.') }}</p>
                    </div>
                @endif
            </div>

            {{-- ================= CENTRE: weight entry + genetics grid ================= --}}
            <div class="flex flex-col gap-4">
                {{-- Weight entry panel (opens when a genetic is chosen). --}}
                @if ($activeGenetic)
                    <section class="rounded-2xl border border-brand/40 bg-brand-tint/40 p-4 dark:border-brand/40 dark:bg-slate-900">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-base font-semibold">{{ $activeGenetic->name }}</h3>
                                    <span class="rounded-full border border-brand/30 bg-brand-tint px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand dark:bg-slate-800 dark:text-slate-200">{{ $activeGenetic->product_type->label() }}</span>
                                </div>
                                @if ($activeGeneticPriceCents !== null)
                                    <p class="text-sm text-ink-muted dark:text-slate-400">{{ $this->money($activeGeneticPriceCents) }} / {{ $activeGenetic->isUnitType() ? __('ud') : 'g' }}</p>
                                @endif
                            </div>
                            <button type="button" wire:click="cancelWeightEntry" class="rounded-lg px-2 py-1 text-sm text-ink-muted hover:bg-black/5 dark:text-slate-400 dark:hover:bg-white/5">{{ __('Cancelar') }}</button>
                        </div>

                        @if (! $activeGenetic->isUnitType())
                        {{-- grams vs calculator (€) toggle --}}
                        <div class="mt-3 inline-flex rounded-xl border border-line bg-surface p-0.5 text-sm dark:border-slate-700 dark:bg-slate-950">
                            <button type="button" wire:click="$set('calculatorMode', false)" @class(['rounded-lg px-3 py-1.5 font-medium', 'bg-brand text-white' => ! $calculatorMode, 'text-ink-muted dark:text-slate-400' => $calculatorMode])>{{ __('Gramos') }}</button>
                            <button type="button" wire:click="toggleCalculator" @class(['rounded-lg px-3 py-1.5 font-medium', 'bg-brand text-white' => $calculatorMode, 'text-ink-muted dark:text-slate-400' => ! $calculatorMode])>{{ __('Calculadora €') }}</button>
                        </div>

                        {{-- display --}}
                        <div class="mt-3 flex items-center justify-between rounded-xl border border-line bg-surface px-4 py-3 dark:border-slate-700 dark:bg-slate-950">
                            <span class="text-sm text-ink-muted dark:text-slate-400">{{ $calculatorMode ? __('Aportación (€)') : __('Peso (g)') }}</span>
                            <span class="text-2xl font-bold tabular-nums">{{ $weightInput === '' ? '0' : $weightInput }}{{ $calculatorMode ? ' €' : ' g' }}</span>
                        </div>

                        {{-- numeric pad --}}
                        <div class="mt-3 grid grid-cols-3 gap-2">
                            @foreach (['1','2','3','4','5','6','7','8','9'] as $digit)
                                <button type="button" wire:click="pad('{{ $digit }}')" class="h-14 rounded-xl border border-line bg-surface text-xl font-semibold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:hover:bg-slate-800">{{ $digit }}</button>
                            @endforeach
                            <button type="button" wire:click="pad(',')" class="h-14 rounded-xl border border-line bg-surface text-xl font-semibold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:hover:bg-slate-800">,</button>
                            <button type="button" wire:click="pad('0')" class="h-14 rounded-xl border border-line bg-surface text-xl font-semibold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:hover:bg-slate-800">0</button>
                            <button type="button" wire:click="pad('back')" class="h-14 rounded-xl border border-line bg-surface text-xl font-semibold text-ink-muted transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-400 dark:hover:bg-slate-800">⌫</button>
                        </div>
                        @else
                            {{-- Unit stepper for a UNIT genetic (preroll/edible). The gauge feedback below
                                 shows the gram-equivalent live as the count steps. --}}
                            <div class="mt-3 flex items-center justify-between rounded-xl border border-line bg-surface px-4 py-3 dark:border-slate-700 dark:bg-slate-950">
                                <span class="text-sm text-ink-muted dark:text-slate-400">{{ __('Unidades') }}</span>
                                <div class="flex items-center gap-3">
                                    <button type="button" wire:click="stepUnits(-1)" aria-label="{{ __('Menos una unidad') }}" class="flex h-12 w-12 items-center justify-center rounded-xl border border-line bg-surface text-2xl font-bold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800">−</button>
                                    <span class="w-12 text-center text-3xl font-bold tabular-nums">{{ $unitQty }}</span>
                                    <button type="button" wire:click="stepUnits(1)" aria-label="{{ __('Más una unidad') }}" class="flex h-12 w-12 items-center justify-center rounded-xl border border-line bg-surface text-2xl font-bold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800">+</button>
                                </div>
                            </div>
                        @endif

                        {{-- Gram-equivalent + real-time ceiling feedback — computed identically for a
                             weighed entry and a stepped unit count (units × grams_per_unit_cg). --}}
                        @if ($activeEntryGramsCg !== null && $activeEntryGramsCg > 0)
                            <div class="mt-3 rounded-xl border border-line bg-surface px-4 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-950">
                                <div class="flex items-center justify-between">
                                    <span class="text-ink-muted dark:text-slate-400">{{ __('Equivale a') }}</span>
                                    <span class="font-semibold tabular-nums">{{ $this->grams($activeEntryGramsCg) }}</span>
                                </div>
                                @if ($limits)
                                    @php $remainingAfter = $limits->dailyRemainingCg() - $activeEntryGramsCg; @endphp
                                    <div class="mt-1 flex items-center justify-between text-xs">
                                        <span class="text-ink-muted dark:text-slate-400">{{ __('Restante hoy tras esta entrada') }}</span>
                                        <span class="font-medium {{ $remainingAfter < 0 ? 'text-error' : 'text-success' }}">{{ $this->grams(max(0, $remainingAfter)) }}</span>
                                    </div>
                                @endif
                            </div>
                        @endif

                        {{-- batch selector (FEFO default, overridable to another dispensable batch) --}}
                        <div class="mt-3">
                            <p class="text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Lote') }}</p>
                            @if ($activeGeneticBatches->isEmpty())
                                <p class="mt-1 rounded-lg border border-error/30 bg-error/10 px-3 py-2 text-sm text-error">{{ __('Sin lote disponible (agotado o caducado).') }}</p>
                            @else
                                <div class="mt-1 flex flex-wrap gap-2">
                                    @foreach ($activeGeneticBatches as $i => $batch)
                                        <button type="button" wire:click="selectBatch('{{ $batch->id }}')" @class([
                                            'rounded-lg border px-3 py-1.5 text-sm transition',
                                            'border-brand bg-brand text-white' => $activeBatchId === $batch->id,
                                            'border-line bg-surface text-ink hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100' => $activeBatchId !== $batch->id,
                                        ])>
                                            {{ $batch->batch_no }}
                                            <span class="opacity-70">· {{ $activeGenetic->isUnitType() ? $batch->remaining_units.' '.__('uds') : $this->grams($batch->remaining_cg?->centigrams ?? 0) }}</span>
                                            @if ($i === 0)<span class="ml-1 rounded-full bg-white/20 px-1.5 py-0.5 text-[10px] font-semibold uppercase">FEFO</span>@endif
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <button type="button" wire:click="addLine" wire:loading.attr="disabled" wire:target="addLine" class="mt-4 h-14 w-full rounded-xl bg-brand text-base font-semibold text-white transition hover:bg-brand-dark focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:opacity-60">{{ __('Añadir a la cesta') }}</button>
                    </section>
                @endif

                {{-- Genetics grid --}}
                <section class="rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <h2 class="text-base font-semibold">{{ __('Genéticas') }}</h2>
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="geneticSearch" aria-label="{{ __('Buscar genética…') }}"
                            autocomplete="off"
                            placeholder="{{ __('Buscar genética…') }}"
                            class="h-10 w-full rounded-xl border border-line bg-surface px-4 text-sm text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 sm:w-56"
                        >
                    </div>

                    {{-- Each filter row is LABELLED (prompt 66) — Categoría (club data), Tipo (product type)
                         and Variedad (strain) are different axes; unlabelled, they read as duplicates. --}}
                    @if (! empty($categories))
                        <div class="mt-3">
                            <p class="mb-1 text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Categoría') }}</p>
                            <div role="group" aria-label="{{ __('Categoría') }}" class="flex flex-wrap gap-2">
                                <button type="button" wire:click="filterCategory(null)" @class(['rounded-full border px-3 py-1 text-sm', 'border-brand bg-brand text-white' => $categoryId === null, 'border-line text-ink-muted dark:border-slate-700 dark:text-slate-400' => $categoryId !== null])>{{ __('Todas') }}</button>
                                @foreach ($categories as $category)
                                    <button type="button" wire:click="filterCategory('{{ $category['id'] }}')" @class(['rounded-full border px-3 py-1 text-sm', 'border-brand bg-brand text-white' => $categoryId === $category['id'], 'border-line text-ink-muted dark:border-slate-700 dark:text-slate-400' => $categoryId !== $category['id']])>{{ $category['name'] }}</button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if (! empty($productTypes))
                        <div class="mt-2">
                            <p class="mb-1 text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Tipo') }}</p>
                            <div role="group" aria-label="{{ __('Tipo') }}" class="flex flex-wrap gap-2">
                                <button type="button" wire:click="filterProductType(null)" @class(['rounded-full border px-3 py-1 text-sm', 'border-brand bg-brand text-white' => $productType === null, 'border-line text-ink-muted dark:border-slate-700 dark:text-slate-400' => $productType !== null])>{{ __('Todos los tipos') }}</button>
                                @foreach ($productTypes as $type)
                                    <button type="button" wire:click="filterProductType('{{ $type['value'] }}')" @class(['rounded-full border px-3 py-1 text-sm', 'border-brand bg-brand text-white' => $productType === $type['value'], 'border-line text-ink-muted dark:border-slate-700 dark:text-slate-400' => $productType !== $type['value']])>{{ $type['label'] }}</button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if (! empty($strainTypes))
                        <div class="mt-2">
                            <p class="mb-1 text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Variedad') }}</p>
                            <div role="group" aria-label="{{ __('Variedad') }}" class="flex flex-wrap gap-2">
                                <button type="button" wire:click="filterStrainType(null)" @class(['rounded-full border px-3 py-1 text-sm', 'border-brand bg-brand text-white' => $strainType === null, 'border-line text-ink-muted dark:border-slate-700 dark:text-slate-400' => $strainType !== null])>{{ __('Todas') }}</button>
                                @foreach ($strainTypes as $variety)
                                    <button type="button" wire:click="filterStrainType('{{ $variety['value'] }}')" @class(['rounded-full border px-3 py-1 text-sm', 'border-brand bg-brand text-white' => $strainType === $variety['value'], 'border-line text-ink-muted dark:border-slate-700 dark:text-slate-400' => $strainType !== $variety['value']])>{{ $variety['label'] }}</button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        @forelse ($genetics as $g)
                            @php $disabledCard = $member === null || ! $g['has_batch']; @endphp
                            <button
                                type="button"
                                @if (! $disabledCard) wire:click="chooseGenetic('{{ $g['id'] }}')" @endif
                                @disabled($disabledCard)
                                @class([
                                    'flex flex-col rounded-xl border p-3 text-left transition',
                                    'border-line bg-surface hover:border-brand hover:bg-brand-tint/40 dark:border-slate-700 dark:bg-slate-950 dark:hover:border-brand' => ! $disabledCard,
                                    'cursor-not-allowed border-dashed border-line bg-surface-alt opacity-60 dark:border-slate-800 dark:bg-slate-900' => $disabledCard,
                                ])
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <span class="font-semibold">{{ $g['name'] }}</span>
                                    <span class="shrink-0 text-sm font-semibold text-brand dark:text-slate-100">{{ $this->money($g['rate_cents']) }}/{{ $g['is_unit'] ? __('ud') : 'g' }}</span>
                                </div>
                                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-ink-muted dark:text-slate-400">
                                    <span class="rounded-full bg-surface-alt px-2 py-0.5 font-semibold text-ink-muted dark:bg-slate-800 dark:text-slate-300">{{ $g['product_type_label'] }}</span>
                                    @if ($g['strain_type_label'])<span class="rounded-full bg-brand-tint px-2 py-0.5 font-semibold text-brand dark:bg-slate-800 dark:text-slate-200">{{ $g['strain_type_label'] }}</span>@endif
                                    <span>THC {{ number_format($g['thc_bp'] / 100, 1) }}%</span>
                                    <span>CBD {{ number_format($g['cbd_bp'] / 100, 1) }}%</span>
                                    @if ($g['cultivation'])<span>{{ __($g['cultivation']) }}</span>@endif
                                </div>
                                <div class="mt-2 flex items-center justify-between text-xs">
                                    <span class="text-ink-muted dark:text-slate-400">{{ __('Stock') }}: {{ $g['is_unit'] ? $g['remaining_units'].' '.__('uds').' ('.$this->grams($g['remaining_cg']).')' : $this->grams($g['remaining_cg']) }}</span>
                                    @if ($g['has_batch'] && $g['low_stock'])
                                        <span class="inline-flex items-center gap-1 text-warning"><span class="h-2 w-2 rounded-full bg-warning"></span>{{ __('Stock bajo') }}</span>
                                    @elseif ($g['has_batch'])
                                        <span class="inline-flex items-center gap-1 text-success"><span class="h-2 w-2 rounded-full bg-success"></span>{{ __('Con lote') }}</span>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-ink-muted dark:text-slate-500"><span class="h-2 w-2 rounded-full bg-slate-400"></span>{{ __('Sin lote') }}</span>
                                    @endif
                                </div>
                                @if ($g['price_label'])
                                    <p class="mt-1 text-[11px] font-medium text-brand dark:text-slate-300">{{ $g['price_label'] }}</p>
                                @endif
                            </button>
                        @empty
                            <p class="col-span-full rounded-xl border border-dashed border-line px-4 py-8 text-center text-sm text-ink-muted dark:border-slate-700 dark:text-slate-400">{{ __('No hay genéticas con precio activo en esta sede.') }}</p>
                        @endforelse
                    </div>
                </section>
            </div>

            {{-- ================= RIGHT: the basket + contribution ================= --}}
            {{-- Pinned to column 2 spanning both rows at lg so it never drops below the genetics grid into
                 dead space; auto-placement at xl for the 3-column sidebar (prompt 91, mirrors bar-pos). --}}
            <div class="flex flex-col gap-4 lg:col-start-2 lg:row-start-1 lg:row-span-2 xl:col-auto xl:row-auto">
                {{-- No open till → unmistakable, blocks commit. --}}
                @unless ($openTill)
                    <div class="rounded-2xl border border-error/40 bg-error/10 p-4 text-sm">
                        <p class="font-semibold text-error">{{ __('No hay caja abierta') }}</p>
                        <p class="mt-1 text-error/90">{{ __('Abre una caja en este terminal antes de dispensar.') }}</p>
                        <a href="{{ route('counter.till') }}" wire:navigate class="mt-3 inline-flex h-10 items-center rounded-lg bg-error px-4 text-sm font-semibold text-white transition hover:opacity-90">{{ __('Ir a la caja') }}</a>
                    </div>
                @endunless

                <section class="rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between">
                        <h2 class="text-base font-semibold">{{ __('Cesta') }}</h2>
                        @if (! empty($basketLines))
                            <button type="button" wire:click="clearBasket" class="rounded-lg px-2 py-1 text-sm text-ink-muted hover:bg-black/5 dark:text-slate-400 dark:hover:bg-white/5">{{ __('Vaciar') }}</button>
                        @endif
                    </div>

                    <ul class="mt-3 divide-y divide-line dark:divide-slate-800">
                        @forelse ($basketLines as $line)
                            <li wire:key="line-{{ $line['index'] }}" class="flex items-start justify-between gap-3 py-2.5">
                                <div class="min-w-0">
                                    <p class="truncate font-medium">
                                        {{ $line['genetic_name'] }}
                                        @if ($line['eighth_applied'] ?? false)
                                            <span class="ml-1 rounded-full border border-brand/30 bg-brand-tint px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand dark:bg-slate-800 dark:text-slate-200">{{ __('1/8') }}</span>
                                        @endif
                                    </p>
                                    <p class="text-xs text-ink-muted dark:text-slate-400">
                                        @if ($line['per_unit'])
                                            {{ $line['units'] }} {{ __('uds') }} ({{ $this->grams($line['grams_cg']) }}) × {{ $this->money($line['rate_cents']) }}/{{ __('ud') }}
                                        @else
                                            {{ $this->grams($line['grams_cg']) }} × {{ $this->money($line['rate_cents']) }}/g
                                        @endif
                                        @if ($line['discount_cents'] > 0)· <span class="text-success">−{{ $this->money($line['discount_cents']) }}</span>@endif
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <span class="font-semibold tabular-nums">{{ $this->money($line['total_cents']) }}</span>
                                    <button type="button" wire:click="removeLine({{ $line['index'] }})" class="rounded-md px-2 py-1 text-ink-muted hover:bg-black/5 dark:text-slate-400 dark:hover:bg-white/5">✕</button>
                                </div>
                            </li>
                        @empty
                            <li class="py-6 text-center text-sm text-ink-muted dark:text-slate-400">{{ __('Cesta vacía. Elige una genética e introduce el peso.') }}</li>
                        @endforelse
                    </ul>

                    {{-- Progressive disclosure (prompt 91): the whole payment apparatus — total, price
                         override, tender, the signature canvas and the commit — appears only once the
                         transaction has taken shape (a line in the basket). Before that there is nothing to
                         pay for, so the two first actions (identify a socio, choose a genetic) are not pushed
                         into the margin by a payment form for a transaction that does not exist. This governs
                         what is SHOWN; once shown, blocked controls still stay clickable and explain (prompt 60). --}}
                    @if (! empty($basketLines))
                    {{-- Total --}}
                    <div class="mt-3 flex items-center justify-between rounded-xl bg-surface-alt px-4 py-3 dark:bg-slate-800">
                        <span class="font-semibold">{{ __('Total aportación') }}</span>
                        <span class="text-lg font-bold tabular-nums">{{ $this->money($basketTotalCents) }}</span>
                    </div>

                    {{-- Price override (prompt 64): permission-gated, reasoned. Comp defective product or a
                         €0 give-away. Leaving the amount blank charges the resolved price. --}}
                    @can('dispensation.price.override')
                        <div class="mt-3 rounded-xl border border-warning/30 bg-warning/5 p-3">
                            <label class="block text-xs font-medium text-warning">{{ __('Ajustar precio (queda registrado)') }}</label>
                            <div class="mt-1 grid gap-2 sm:grid-cols-2">
                                <input type="text" inputmode="decimal" wire:model.blur="priceOverrideEuros" autocomplete="off" placeholder="{{ __('Nuevo total (€)') }}" class="h-11 w-full rounded-xl border border-line bg-surface px-3 text-base text-ink placeholder:text-ink-muted focus:border-warning focus:outline-none focus:ring-2 focus:ring-warning/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                                <input type="text" wire:model.blur="priceOverrideReason" autocomplete="off" placeholder="{{ __('Motivo (p. ej. producto defectuoso)') }}" class="h-11 w-full rounded-xl border border-line bg-surface px-3 text-base text-ink placeholder:text-ink-muted focus:border-warning focus:outline-none focus:ring-2 focus:ring-warning/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                            </div>
                            <p class="mt-1 text-[11px] text-ink-muted dark:text-slate-500">{{ __('Deja el importe vacío para cobrar el precio normal. 0 € = gratis.') }}</p>
                        </div>
                    @endcan

                    {{-- Tender (prompt 74): wallet APPLIED + physical cash TENDERED → change. The cash field is
                         what the member handed over, never the charge; the recorded contribution is the total. --}}
                    <div class="mt-4 space-y-3">
                        <div>
                            <label for="wallet" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Monedero (€)') }}</label>
                            <input id="wallet" type="text" inputmode="decimal" wire:model.live.debounce.400ms="walletInput" @disabled($member === null) autocomplete="off" placeholder="0,00" class="mt-1 h-11 w-full rounded-xl border border-line bg-surface px-3 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                            @if ($member === null)
                                <p class="mt-1 text-[11px] text-ink-muted dark:text-slate-500">{{ __('Atribuye un socio para pagar con monedero.') }}</p>
                            @endif
                        </div>

                        {{-- Quick cash --}}
                        <div>
                            <p class="text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Efectivo entregado') }}</p>
                            <div class="mt-1 grid grid-cols-4 gap-2">
                                <button type="button" wire:click="quickCash" class="h-11 rounded-xl border border-line bg-surface text-sm font-semibold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:hover:bg-slate-800">{{ __('Justo') }}</button>
                                <button type="button" wire:click="quickCash(500)" class="h-11 rounded-xl border border-line bg-surface text-sm font-semibold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:hover:bg-slate-800">€5</button>
                                <button type="button" wire:click="quickCash(1000)" class="h-11 rounded-xl border border-line bg-surface text-sm font-semibold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:hover:bg-slate-800">€10</button>
                                <button type="button" wire:click="quickCash(2000)" class="h-11 rounded-xl border border-line bg-surface text-sm font-semibold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:hover:bg-slate-800">€20</button>
                            </div>
                            <input type="text" inputmode="decimal" wire:model.live.debounce.400ms="cashTendered" autocomplete="off" placeholder="{{ __('Efectivo entregado (€)') }}" class="mt-2 h-11 w-full rounded-xl border border-line bg-surface px-3 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                        </div>

                        <dl class="space-y-1 rounded-xl bg-surface-alt px-4 py-3 text-sm dark:bg-slate-800">
                            <div class="flex items-center justify-between">
                                <dt class="text-ink-muted dark:text-slate-400">{{ __('A cobrar en efectivo') }}</dt>
                                <dd class="font-semibold tabular-nums">{{ $this->money($cashPreviewCents) }}</dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt class="text-ink-muted dark:text-slate-400">{{ __('Monedero') }}</dt>
                                <dd class="font-semibold tabular-nums">{{ $this->money($walletPreviewCents) }}</dd>
                            </div>
                            <div class="flex items-center justify-between border-t border-line pt-1 dark:border-slate-700">
                                <dt class="font-medium">{{ __('Cambio') }}</dt>
                                <dd class="text-base font-bold tabular-nums {{ $changeDueCents > 0 ? 'text-success' : '' }}">{{ $this->money($changeDueCents) }}</dd>
                            </div>
                            <div class="flex items-center justify-between text-xs text-ink-muted dark:text-slate-400">
                                <dt>{{ __('Monedero tras aportación') }}</dt>
                                <dd class="font-medium {{ $projectedWalletCents < 0 ? 'text-error' : '' }}">{{ $this->money($projectedWalletCents) }}</dd>
                            </div>
                        </dl>
                    </div>

                    {{-- Signature (only when the sede requires it). --}}
                    @if ($requireSignature)
                        <div class="mt-4 border-t border-line pt-4 dark:border-slate-800">
                            <p class="text-sm font-medium">{{ __('Firma del socio') }}</p>
                            @if ($signaturePath)
                                <div class="mt-2 flex items-center justify-between rounded-xl border border-success/30 bg-success/10 px-3 py-2 text-sm text-success">
                                    <span>✓ {{ __('Firma capturada') }}</span>
                                    <button type="button" wire:click="clearSignature" class="rounded-md px-2 py-0.5 text-success/80 hover:text-success">{{ __('Rehacer') }}</button>
                                </div>
                            @else
                                <div
                                    x-data="{
                                        drawing: false, ctx: null,
                                        init() {
                                            const c = this.$refs.pad; c.width = c.offsetWidth; c.height = 150;
                                            this.ctx = c.getContext('2d'); this.ctx.lineWidth = 2; this.ctx.lineCap = 'round'; this.ctx.strokeStyle = '#2563eb';
                                        },
                                        point(e) { const r = this.$refs.pad.getBoundingClientRect(); const t = e.touches ? e.touches[0] : e; return { x: t.clientX - r.left, y: t.clientY - r.top }; },
                                        start(e) { this.drawing = true; const p = this.point(e); this.ctx.beginPath(); this.ctx.moveTo(p.x, p.y); },
                                        move(e) { if (! this.drawing) return; const p = this.point(e); this.ctx.lineTo(p.x, p.y); this.ctx.stroke(); },
                                        stop() { this.drawing = false; },
                                        wipe() { this.ctx.clearRect(0, 0, this.$refs.pad.width, this.$refs.pad.height); },
                                        save() { $wire.saveSignature(this.$refs.pad.toDataURL('image/png')); },
                                    }"
                                    class="mt-2"
                                >
                                    <canvas
                                        x-ref="pad"
                                        class="w-full touch-none rounded-xl border border-line bg-white dark:border-slate-700"
                                        @mousedown="start($event)" @mousemove="move($event)" @mouseup="stop()" @mouseleave="stop()"
                                        @touchstart.prevent="start($event)" @touchmove.prevent="move($event)" @touchend="stop()"
                                    ></canvas>
                                    <div class="mt-2 flex gap-2">
                                        <button type="button" @click="wipe()" class="h-10 flex-1 rounded-lg border border-line bg-surface-alt text-sm font-medium text-ink-muted dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ __('Borrar') }}</button>
                                        <button type="button" @click="save()" class="h-10 flex-1 rounded-lg bg-brand text-sm font-semibold text-white hover:bg-brand-dark">{{ __('Guardar firma') }}</button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif

                    {{-- Override (permissioned + reasoned). --}}
                    @if ($requireOverride)
                        <div class="mt-4 rounded-xl border border-warning/40 bg-warning/5 p-3">
                            <p class="text-sm font-semibold text-warning">{{ $limitBreach ? __('Supera el límite de consumo') : __('Requiere autorización') }}</p>
                            @if ($canOverride)
                                <textarea wire:model="overrideReason" rows="2" placeholder="{{ __('Motivo de la excepción (queda registrado)') }}" class="mt-2 w-full rounded-xl border border-warning/40 bg-surface px-3 py-2 text-sm focus:border-warning focus:outline-none focus:ring-2 focus:ring-warning/40 dark:bg-slate-950"></textarea>
                                <button type="button" wire:click="commitWithOverride" wire:loading.attr="disabled" wire:target="commitWithOverride" x-bind:disabled="! online" class="mt-2 h-12 w-full rounded-xl bg-warning px-4 text-base font-semibold text-white transition hover:opacity-90 disabled:opacity-60">{{ __('Autorizar y registrar') }}</button>
                            @else
                                <p class="mt-2 text-sm text-ink-muted dark:text-slate-400">{{ __('Un responsable con permiso (limits.override) debe autorizar esta excepción.') }}</p>
                            @endif
                        </div>
                    @endif

                    {{-- Colocated confirmation (prompt 60, mirroring the bar POS prompt-41 block): the flash
                         ALSO renders here at the point of action, so a commit's outcome — success OR a blocked
                         reason — is visible without scrolling to the page-top banner. --}}
                    @if ($flashMessage)
                        <div
                            wire:key="flash-commit"
                            role="{{ $flashType === 'error' ? 'alert' : 'status' }}"
                            aria-live="{{ $flashType === 'error' ? 'assertive' : 'polite' }}"
                            @class([
                                'mt-4 rounded-xl border px-4 py-3 text-sm font-semibold',
                                'border-success/30 bg-success/10 text-success' => $flashType === 'success',
                                'border-warning/30 bg-warning/10 text-warning' => $flashType === 'warning',
                                'border-error/30 bg-error/10 text-error' => $flashType === 'error',
                            ])
                        >
                            {{ $flashMessage }}
                        </div>
                    @endif

                    @else
                        {{-- Empty basket: no heavy payment apparatus (tender, signature, breakdown), just the
                             next step. The commit stays below (prompt 60), it simply has nothing to charge yet. --}}
                        <p data-empty-basket-hint class="mt-3 rounded-xl border border-dashed border-line px-4 py-6 text-center text-sm text-ink-muted dark:border-slate-700 dark:text-slate-400">
                            {{ __('Identifica a un socio y añade una genética para empezar.') }}
                        </p>
                    @endif

                    {{-- Commit — ALWAYS shown and disabled ONLY when offline (prompt 60). Every other blocked
                         state (no socio, empty basket, a hard block, missing signature) stays CLICKABLE, and
                         commit() flashes its reason into the colocated block above — never a silent dead control. --}}
                    <button
                        type="button"
                        wire:click="commit"
                        wire:loading.attr="disabled"
                        wire:target="commit"
                        x-bind:disabled="! online"
                        class="mt-4 h-16 w-full rounded-xl bg-brand text-lg font-bold text-white transition hover:bg-brand-dark focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="commit">{{ __('Registrar aportación') }}</span>
                        <span wire:loading wire:target="commit">{{ __('Registrando…') }}</span>
                    </button>
                    @if ($member === null)
                        <p class="mt-2 text-center text-xs text-ink-muted dark:text-slate-500">{{ __('Identifica a un socio para poder registrar.') }}</p>
                    @endif
                </section>

                {{-- Just committed → receipt + void affordance. --}}
                @if ($lastDispensationId)
                    <section class="rounded-2xl border border-success/30 bg-success/5 p-4">
                        <p class="text-sm font-semibold text-success">{{ __('Última dispensación registrada') }}</p>
                        <div class="mt-3 flex flex-col gap-2">
                            <a href="{{ route('counter.pos.receipt', $lastDispensationId) }}" target="_blank" rel="noopener" class="inline-flex h-11 items-center justify-center rounded-xl border border-line bg-surface px-4 text-sm font-semibold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">{{ __('Ver / imprimir recibo') }}</a>

                            <button type="button" wire:click="emailReceipt" wire:loading.attr="disabled" wire:target="emailReceipt" class="inline-flex h-11 items-center justify-center rounded-xl border border-line bg-surface px-4 text-sm font-semibold text-ink transition hover:bg-surface-alt disabled:opacity-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">{{ __('Enviar comprobante por email') }}</button>

                            @if ($canVoid)
                                <div class="rounded-xl border border-line bg-surface p-3 dark:border-slate-700 dark:bg-slate-900">
                                    <label class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Anular esta dispensación') }}</label>
                                    <textarea wire:model="voidReason" rows="2" placeholder="{{ __('Motivo de la anulación (queda registrado)') }}" class="mt-1 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm focus:border-error focus:outline-none focus:ring-2 focus:ring-error/30 dark:border-slate-700 dark:bg-slate-950"></textarea>
                                    <button type="button" wire:click="voidLast" wire:confirm="{{ __('¿Anular la dispensación? Se revertirán stock y monedero.') }}" class="mt-2 h-10 w-full rounded-lg border border-error/40 bg-error/10 text-sm font-semibold text-error transition hover:bg-error/20">{{ __('Anular') }}</button>
                                </div>
                            @endif
                        </div>
                    </section>
                @endif
            </div>
        </div>
    @endif
</div>

{{-- Prompt 23: flag unsaved counter work so the header's Panel/Log out confirm before leaving. --}}
@script
<script>
    const sync = () => { if (window.Alpine?.store('counter')) window.Alpine.store('counter').dirty = (($wire.basket?.length ?? 0) > 0); };
    $wire.$watch('basket', sync);
    sync();
</script>
@endscript
