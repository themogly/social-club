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
        },
    }"
    {{-- Prompt 176: the component root carries the height so the two-pane child can resolve `h-full`
         against a DEFINITE height and the cart column can be constrained instead of overflowing.
         `md:` only — below that the layout is a normal stacking, scrolling page. --}}
    class="md:h-full"
>
    @include('livewire.counter.partials.counter-surface')

    @if (! $this->handoverActive())

    {{-- Prompt 175 — the four preconditions resolved to ONE, in dependency order. The operator step is in the
         chain (so till and member cannot jump it) but is rendered by 173's surface, never here. --}}
    @php
        $blocker = \App\Support\CounterBlocker::first([
            \App\Support\CounterBlocker::SEDE => ! $noLocation,
            \App\Support\CounterBlocker::OPERATOR => $this->hasOperator(),
            \App\Support\CounterBlocker::TILL => $openTill !== null,
            \App\Support\CounterBlocker::MEMBER => $member !== null,
        ]);
    @endphp

    {{-- Above the branch on purpose: losing the connection, or the reason a commit was refused, must reach the
         operator whichever state the screen is in. A blocking state replaces the work, not the warnings. --}}
    @if (! $noLocation)
        {{-- Offline banner — unmistakable, fail closed. --}}
        <div x-show="! online" x-cloak role="alert" aria-live="assertive" class="mb-4 flex items-center gap-3 rounded-xl border border-error/40 bg-error/10 px-4 py-3 text-sm font-semibold text-error">
            <span class="text-lg">⚠️</span>
            <span>{{ __('Sin conexión. No se puede registrar ninguna dispensación; la cesta se conserva y se reactivará al reconectar.') }}</span>
        </div>

        {{-- The flash lived here unconditionally and, with prompt 60's colocated block below, rendered the
             SAME message twice whenever a basket was on screen (prompt 199). It now follows the bar's shape
             from 193: two positions, one partial, never both at once.

             Here it belongs only when a blocking state has replaced the work AND the cart column — then the
             reason has nowhere else to go, exactly as the original comment argued. --}}
        @if (\App\Support\CounterBlocker::rendersInPage($blocker))
            @include('livewire.counter.partials.counter-flash', ['anchor' => 'data-blocked-feedback'])
        @endif
    @endif

    @if (\App\Support\CounterBlocker::rendersInPage($blocker))
        @if ($blocker === \App\Support\CounterBlocker::SEDE)
            {{-- The fix is the topbar sede switcher, which is already on screen — so no button here. When no
                 sede is assigned at all only a responsable can fix it, and saying so is the honest state. --}}
            <x-counter.blocking-state
                data-blocker="sede"
                icon="📍"
                :heading="$mustChooseLocation ? __('Elige tu sede') : __('Sin sede asignada')"
                :body="$mustChooseLocation ? __('Trabajas en varias sedes. Selecciona en la barra superior en cuál estás.') : __('No tienes ninguna sede activa. Pide a un responsable que te asigne una para dispensar.')"
            />
        @elseif ($blocker === \App\Support\CounterBlocker::TILL)
            {{-- Was a red card with a dark-red button among the basket's cards. The reason it gave is kept,
                 said once; the button is navigation, so it is the brand button. Prompt 182 redesigns the
                 screen it leads to — this branch only stops lying about the colour. --}}
            <x-counter.blocking-state
                data-blocker="till"
                icon="🧾"
                :heading="__('No hay caja abierta')"
                :body="__('Abre una caja en este terminal antes de dispensar.')"
                :action-label="__('Ir a la caja')"
                :action-href="route('counter.till')"
            />
        @else
            {{-- The member step carries its own fix — the lookup itself, not a link elsewhere. Replaced BOTH
                 the grey empty state in the left column and the grey helper text under the commit button,
                 which said the same thing twice in two styles.

                 Prompt 194 — ONE field, the shared one. This blocking state used to stack a scan box above a
                 name box (partials/member-identify), each already accepting what the other asked for. --}}
            <x-counter.blocking-state
                data-blocker="member"
                icon="🪪"
                :heading="__('Identifica a un socio')"
                :body="__('Sin socio no se puede registrar ninguna dispensación.')"
            >
                @include('livewire.counter.partials.member-lookup', ['autofocus' => true])
                @include('livewire.counter.partials.checked-in-required')
            </x-counter.blocking-state>
        @endif
    @else
        {{-- Prompt 91 — the SAME basket-column pattern batch 2 gave the bar POS (the dispensary was never
             given it): at lg (1024, the counter's tablet-first width) the basket + contribution (RIGHT) is
             pinned to a dedicated column 2 spanning both rows, so socio (LEFT) + genetics (CENTRE) stack in
             column 1 with no dead space and the primary action stays top-right. At xl the RIGHT div resets
             to auto-placement for the 3-column sidebar layout. Kept identical to bar-pos on purpose — one
             layout, asserted by both PosLayout tests. --}}
        {{-- Prompt 176 — TWO PANES, and only one of them scrolls.

             Measured on `main` (592c93c, after `npm run build`) at the two tablet orientations: with a
             socio identified and three lines in the basket, `Registrar aportación` sat 186px below the
             fold at 1180x820 and 939px below it at 820x1180. The bar was 149px and 693px below. The
             counter was a single vertical stack that got longer as work was added, so the button that
             takes the money moved further away the more there was to take.

             A POS is two panes and one of them never moves. The SELECTION pane scrolls; the CART column
             is fixed, carries identity and the allowance at its top, the basket in its middle, and the
             commit action at its foot where it is always reachable.

             Deliberately NOT a bottom bar pinned to the viewport: that is a phone convention. On a tablet
             — rested on a surface in about two thirds of sessions — the bottom edge is the hostile zone,
             never near the thumbs and occluded by a standing operator's own wrist. Toast, Treez and
             Flowhub all put the commit at the foot of the CART COLUMN, not of the screen. --}}
        {{-- Hoisted (prompt 176): the member card was split between the cart's fixed head and its scroll
             region, so the two values both halves need are computed once, above the split, and guarded —
             the OPERATOR step renders this branch with the 173 surface over it and no socio resolved. --}}
        @php
            $inCarencia = $member !== null && $member->carencia_ends_at !== null && $member->carencia_ends_at->isFuture();
            $statusColour = $member === null ? '' : match ($member->status) {
                \App\Enums\MemberStatus::ACTIVE => 'border-success/30 bg-success/10 text-success',
                \App\Enums\MemberStatus::APPLICANT => 'border-warning/30 bg-warning/10 text-warning',
                \App\Enums\MemberStatus::SUSPENDED, \App\Enums\MemberStatus::EXPELLED => 'border-error/30 bg-error/10 text-error',
                default => 'border-line bg-surface-alt text-ink-muted dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300',
            };
        @endphp

        @php
            // A PRESENT but ineligible socio is a blocking state like any other (prompt 225) — it replaces
            // the work rather than sitting beside it. Read once, because three places branch on it: the
            // selection pane, the cart's verdict list (which drops its duplicate copy) and the commit's
            // reason line.
            $blockedSurface = $member !== null && ! empty($hardBlockRules);
        @endphp

        <div class="flex h-full min-h-0 flex-col gap-4 md:flex-row">

            {{-- ================= SELECTION: the only thing that scrolls ================= --}}
            <div
                data-selection-pane
                class="flex min-h-0 flex-1 flex-col gap-4 md:overflow-y-auto md:pr-1"
            >
            @if ($blockedSurface)
                @include('livewire.counter.partials.blocked-member')
            @else
                {{-- Identify — the same shared lookup the member blocking state uses, wrapped in this column's
                     card chrome. Kept here so an operator can scan the next socio without clearing the current
                     one first; the audit's finding 3 (this column eating the top of the screen) is prompt 176.
                     No autofocus: a basket is already in progress and the cursor belongs to it. --}}
                <section class="rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900">
                    @include('livewire.counter.partials.member-lookup', ['autofocus' => false])
                    @include('livewire.counter.partials.checked-in-required')
                </section>
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

                        {{-- One-tap weight presets (prompt 133): each shows its resulting price; 3,5 g shows the
                             eighth break. A preset over the member's remaining allowance is shown unavailable, not
                             refused after the tap. It only FILLS the weight input — the same checks apply at add. --}}
                        @if (! $calculatorMode && ! empty($weightPresets))
                            <div class="mt-3 grid grid-cols-4 gap-2" data-weight-presets>
                                @foreach ($weightPresets as $preset)
                                    <button
                                        type="button"
                                        @if ($preset['available']) wire:click="applyWeightPreset({{ $preset['grams_cg'] }})" @else disabled aria-disabled="true" @endif
                                        data-weight-preset="{{ $preset['grams_cg'] }}"
                                        @class([
                                            'flex min-h-11 flex-col items-center justify-center rounded-xl border px-1 py-1 text-sm font-semibold transition',
                                            'border-line bg-surface text-ink hover:bg-brand-tint hover:text-brand dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100' => $preset['available'],
                                            'cursor-not-allowed border-line/60 text-ink-muted opacity-40 dark:border-slate-800' => ! $preset['available'],
                                        ])
                                    >
                                        <span>{{ $preset['label'] }} g</span>
                                        @if ($preset['price_cents'] !== null)
                                            <span @class(['text-[11px] font-medium', 'text-brand' => $preset['eighth_applied'], 'text-ink-muted dark:text-slate-400' => ! $preset['eighth_applied']])>
                                                {{ $this->money($preset['price_cents']) }}@if ($preset['eighth_applied']) · ⅛@endif
                                            </span>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        @endif

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

                        <x-button size="lg" class="mt-4 w-full" wire:click="addLine" wire:loading.attr="disabled" wire:target="addLine">{{ __('Añadir a la cesta') }}</x-button>
                    </section>
                @endif

                {{-- Genetics grid --}}
                <section class="rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900">
                    {{-- Prompt 176: stacks until lg. At 820 portrait the selection pane is ~470px and
                         title + view toggle + search do not fit on one row without clipping. --}}
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        {{-- THE TOGGLE IS THE HEADING (prompt 212).

                             The owner asked for the heading to read *"Dispensario"*. Two reasons it does
                             something slightly different instead. **"Genéticas" was wrong on the facts**, so
                             the rename is right: `ProductType` is FLOWER / CONCENTRATE / PREROLL / EDIBLE and
                             prompt 66's own filters say so — the word under-described what was already in the
                             pane. But *"Dispensario"* would name the panel after the screen it sits on (the
                             bar already reads "· Dispensario"), and once the pane holds bar products it is not
                             the dispensary; it is a catalogue with two sources.

                             So the toggle is the heading, with nothing above it. It names what you are
                             browsing, it is the control you came to press, and it removes a word rather than
                             replacing one. A heading AND a toggle saying the same thing would be worse than
                             either. --}}
                        <div role="group" aria-label="{{ __('Qué estás mirando') }}" data-catalogue-source="{{ $catalogueSource }}"
                             class="flex w-fit shrink-0 gap-1 self-start rounded-xl border border-line p-1 dark:border-slate-700">
                            {{-- Block form, not `@php(…)`: Blade lifts raw PHP out with a non-greedy
                                 `/(?<!@)@php(.*?)@endphp/s` BEFORE compiling directives, so a shorthand in a
                                 file that uses the block form later pairs with THAT file's next `@endphp` and
                                 swallows everything between (prompt 207 hit the same trap). --}}
                            @php
                                $sources = $barEnabled
                                    ? ['genetics' => __('Dispensario'), 'bar' => __('Barra')]
                                    : ['genetics' => __('Dispensario')];
                            @endphp
                            @foreach ($sources as $source => $label)
                                <button
                                    type="button"
                                    wire:click="setCatalogueSource('{{ $source }}')"
                                    data-source-option="{{ $source }}"
                                    aria-pressed="{{ $catalogueSource === $source ? 'true' : 'false' }}"
                                    @class([
                                        'inline-flex min-h-11 items-center rounded-lg px-4 text-base font-semibold transition',
                                        'bg-brand text-white' => $catalogueSource === $source,
                                        'text-ink-muted hover:bg-surface-alt dark:text-slate-400 dark:hover:bg-slate-800' => $catalogueSource !== $source,
                                    ])
                                >{{ $label }}</button>
                            @endforeach
                        </div>
                        {{-- List / grid. LIST is the default for genetics — see DispensaryPos::$geneticLayout.
                             Applies to BOTH sources (prompt 212): it is a density preference about cards, not
                             a fact about cannabis, and an operator who prefers a grid prefers it for both. --}}
                        <div role="group" aria-label="{{ __('Vista') }}" class="flex w-fit shrink-0 gap-1 self-start rounded-xl border border-line p-1 dark:border-slate-700">
                            @foreach ([['list', __('Lista'), '☰'], ['grid', __('Cuadrícula'), '▦']] as [$mode, $label, $glyph])
                                <button
                                    type="button"
                                    wire:click="setGeneticLayout('{{ $mode }}')"
                                    data-layout-option="{{ $mode }}"
                                    aria-label="{{ $label }}"
                                    aria-pressed="{{ $this->catalogueLayout() === $mode ? 'true' : 'false' }}"
                                    @class([
                                        'inline-flex h-11 w-11 items-center justify-center rounded-lg text-base transition',
                                        'bg-brand text-white' => $this->catalogueLayout() === $mode,
                                        'text-ink-muted hover:bg-surface-alt dark:text-slate-400 dark:hover:bg-slate-800' => $this->catalogueLayout() !== $mode,
                                    ])
                                >{{ $glyph }}</button>
                            @endforeach
                        </div>

                        {{-- One search box per source, and they keep their own terms: switching source to check
                             a price and switching back must not have cleared what you were looking for. NOT a
                             member search (194) — this is a catalogue. --}}
                        @if ($catalogueSource === 'bar')
                            <input
                                type="text"
                                wire:model.live.debounce.300ms="articleSearch" aria-label="{{ __('Buscar artículo…') }}"
                                data-article-search
                                autocomplete="off"
                                placeholder="{{ __('Buscar artículo…') }}"
                                class="h-11 w-full rounded-xl border border-line bg-surface px-4 text-sm text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 sm:w-56"
                            >
                        @else
                            <input
                                type="text"
                                wire:model.live.debounce.300ms="geneticSearch" aria-label="{{ __('Buscar genética…') }}"
                                autocomplete="off"
                                placeholder="{{ __('Buscar genética…') }}"
                                class="h-11 w-full rounded-xl border border-line bg-surface px-4 text-sm text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 sm:w-56"
                            >
                        @endif
                    </div>

                    {{-- "Their usual" (prompt 133): the member's recent genetics, one tap each, only those
                         sellable at this sede right now. Combined with a weight preset, a regular's order is one
                         or two taps. Sourced once on identification. --}}
                    {{-- Genetics only (prompt 212): it is built from this member's DISPENSATION history, so on
                         the bar source it would either be empty or, worse, show genetics while you are
                         browsing drinks. Hidden for that source rather than shown empty. --}}
                    @if ($catalogueSource === 'genetics' && ! empty($usualGenetics))
                        <div class="mt-3" data-usual-genetics>
                            <p class="mb-1 text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Su habitual') }}</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($usualGenetics as $usual)
                                    <button
                                        type="button"
                                        wire:click="chooseGenetic('{{ $usual['id'] }}')"
                                        data-usual-genetic="{{ $usual['id'] }}"
                                        class="inline-flex min-h-11 items-center gap-1.5 rounded-full border border-brand/40 bg-brand-tint px-4 text-sm font-semibold text-brand transition hover:bg-brand hover:text-white dark:bg-slate-800 dark:text-slate-100"
                                    >{{ $usual['name'] }}</button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Prompt 176 — the filters are COLLAPSED by default, and search is the primary route.

                         Three labelled filter rows (Categoría, Tipo, Variedad) stood between the heading and
                         the first genetic; measured on main, the list began past y=1190 in an 820px viewport.
                         Treez, the cannabis POS, is search-first for exactly this reason: a genetic carries
                         lab figures, a category and live stock that no row of pills summarises. So search is
                         at the top, always visible, and the axes are one tap away — labelled as before
                         (prompt 66: unlabelled, the three read as duplicates), and opened automatically when
                         a filter is already applied so an active filter is never hidden from the operator. --}}
                    {{-- Which of these apply to ARTICLES (prompt 212): Categoría does — an article carries one,
                         and it is the same club-authored taxonomy. Tipo and Variedad do not: `ProductType` and
                         strain are facts about cannabis and would render as an empty row on the bar source,
                         which is the "shown empty" this branch was told to avoid. So the bar gets one filter
                         row, its own, and the pane's furniture follows the source. --}}
                    @php
                        $paneCategories = $catalogueSource === 'bar' ? $articleCategories : $categories;
                        $activeCategoryId = $catalogueSource === 'bar' ? $articleCategoryId : $categoryId;
                        $categoryAction = $catalogueSource === 'bar' ? 'filterArticleCategory' : 'filterCategory';
                        $activeFilters = $catalogueSource === 'bar'
                            ? ($articleCategoryId !== null ? 1 : 0)
                            : collect([$categoryId, $productType, $strainType])->filter()->count();
                    @endphp
                    <div x-data="{ open: {{ $activeFilters > 0 ? 'true' : 'false' }} }" class="mt-3">
                        <button
                            type="button"
                            x-on:click="open = ! open"
                            x-bind:aria-expanded="open ? 'true' : 'false'"
                            class="inline-flex h-11 items-center gap-2 rounded-xl border border-line px-4 text-sm font-medium text-ink-muted transition hover:bg-surface-alt dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800"
                        >
                            {{ __('Filtros') }}
                            @if ($activeFilters > 0)
                                <span class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-brand px-1.5 text-[11px] font-bold text-white">{{ $activeFilters }}</span>
                            @endif
                            <span aria-hidden="true" x-text="open ? '\u25b4' : '\u25be'"></span>
                        </button>

                        <div x-show="open" x-cloak>
                    {{-- Each filter row is LABELLED (prompt 66) — Categoría (club data), Tipo (product type)
                         and Variedad (strain) are different axes; unlabelled, they read as duplicates. --}}
                    @if (! empty($paneCategories))
                        <div class="mt-3">
                            <p class="mb-1 text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Categoría') }}</p>
                            <div role="group" aria-label="{{ __('Categoría') }}" class="flex flex-wrap gap-2">
                                <button type="button" wire:click="{{ $categoryAction }}(null)" @class(['inline-flex items-center rounded-full border min-h-11 px-4 text-sm', 'border-brand bg-brand text-white' => $activeCategoryId === null, 'border-line text-ink-muted dark:border-slate-700 dark:text-slate-400' => $activeCategoryId !== null])>{{ __('Todas') }}</button>
                                @foreach ($paneCategories as $category)
                                    <button type="button" wire:click="{{ $categoryAction }}('{{ $category['id'] }}')" @class(['inline-flex items-center rounded-full border min-h-11 px-4 text-sm', 'border-brand bg-brand text-white' => $activeCategoryId === $category['id'], 'border-line text-ink-muted dark:border-slate-700 dark:text-slate-400' => $activeCategoryId !== $category['id']])>{{ $category['name'] }}</button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($catalogueSource === 'genetics' && ! empty($productTypes))
                        <div class="mt-2">
                            <p class="mb-1 text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Tipo') }}</p>
                            <div role="group" aria-label="{{ __('Tipo') }}" class="flex flex-wrap gap-2">
                                <button type="button" wire:click="filterProductType(null)" @class(['inline-flex items-center rounded-full border min-h-11 px-4 text-sm', 'border-brand bg-brand text-white' => $productType === null, 'border-line text-ink-muted dark:border-slate-700 dark:text-slate-400' => $productType !== null])>{{ __('Todos los tipos') }}</button>
                                @foreach ($productTypes as $type)
                                    <button type="button" wire:click="filterProductType('{{ $type['value'] }}')" @class(['inline-flex items-center rounded-full border min-h-11 px-4 text-sm', 'border-brand bg-brand text-white' => $productType === $type['value'], 'border-line text-ink-muted dark:border-slate-700 dark:text-slate-400' => $productType !== $type['value']])>{{ $type['label'] }}</button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($catalogueSource === 'genetics' && ! empty($strainTypes))
                        <div class="mt-2">
                            <p class="mb-1 text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Variedad') }}</p>
                            <div role="group" aria-label="{{ __('Variedad') }}" class="flex flex-wrap gap-2">
                                <button type="button" wire:click="filterStrainType(null)" @class(['inline-flex items-center rounded-full border min-h-11 px-4 text-sm', 'border-brand bg-brand text-white' => $strainType === null, 'border-line text-ink-muted dark:border-slate-700 dark:text-slate-400' => $strainType !== null])>{{ __('Todas') }}</button>
                                @foreach ($strainTypes as $variety)
                                    <button type="button" wire:click="filterStrainType('{{ $variety['value'] }}')" @class(['inline-flex items-center rounded-full border min-h-11 px-4 text-sm', 'border-brand bg-brand text-white' => $strainType === $variety['value'], 'border-line text-ink-muted dark:border-slate-700 dark:text-slate-400' => $strainType !== $variety['value']])>{{ $variety['label'] }}</button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                        </div>
                    </div>

                    {{-- THE BAR SOURCE — the same card shape, the same layout toggle, the same 44px floor.
                         A tap adds a BAR line (`addBarItem`), never a dispensation: the toggle changes what
                         you browse, not which basket you fill, and the cart keeps them in two labelled
                         sections. Stock is a STATE (185), never a count. --}}
                    @if ($catalogueSource === 'bar')
                        <div @class([
                            'mt-4',
                            'flex flex-col gap-2' => $this->catalogueLayout() === 'list',
                            'grid gap-3 sm:grid-cols-2' => $this->catalogueLayout() === 'grid',
                        ])>
                            @forelse ($barArticles as $article)
                                <button
                                    type="button"
                                    wire:click="addBarItem('{{ $article['id'] }}')"
                                    data-product
                                    data-bar-article="{{ $article['id'] }}"
                                    @class([
                                        'flex w-full min-h-11 rounded-xl border border-line px-3 py-1.5 text-left transition hover:border-brand hover:bg-brand-tint dark:border-slate-700 dark:hover:bg-slate-800',
                                        'flex-col gap-1' => $this->catalogueLayout() === 'grid',
                                        'flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-4' => $this->catalogueLayout() === 'list',
                                    ])
                                >
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-semibold leading-tight">{{ $article['name'] }}</span>
                                        <span class="mt-0.5 flex flex-wrap items-center gap-1.5 text-[11px] leading-tight text-ink-muted dark:text-slate-400">
                                            @if ($article['category_name'])
                                                <span>{{ $article['category_name'] }}</span>
                                            @endif
                                            @if ($article['low_stock'])
                                                <span data-bar-stock-state class="rounded-full bg-warning/10 px-2 py-0.5 font-semibold text-warning">{{ __('Quedan pocas') }}</span>
                                            @endif
                                        </span>
                                    </span>
                                    <span class="shrink-0 text-sm font-semibold tabular-nums">{{ $article['price_label'] }}</span>
                                </button>
                            @empty
                                <p class="rounded-xl border border-dashed border-line px-4 py-6 text-center text-sm text-ink-muted dark:border-slate-700 dark:text-slate-400">
                                    {{ $articleSearch !== '' || $articleCategoryId !== null
                                        ? __('Ningún artículo coincide con la búsqueda.')
                                        : __('No hay artículos disponibles en esta sede.') }}
                                </p>
                            @endforelse
                        </div>
                    @else
                    <div @class([
                        'mt-4',
                        'flex flex-col gap-2' => $this->catalogueLayout() === 'list',
                        'grid gap-3 sm:grid-cols-2' => $this->catalogueLayout() === 'grid',
                    ])>
                        @forelse ($genetics as $g)
                            @php $disabledCard = $member === null || ! $g['has_batch']; @endphp
                            <button
                                type="button"
                                @if (! $disabledCard) wire:click="chooseGenetic('{{ $g['id'] }}')" @endif
                                @disabled($disabledCard)
                                data-product
                                @class([
                                    'flex w-full min-h-11 rounded-xl border px-3 py-1.5 text-left transition',
                                    'flex-col gap-1' => $this->catalogueLayout() === 'grid',
                                    'flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-4' => $this->catalogueLayout() === 'list',
                                    'border-line bg-surface hover:border-brand hover:bg-brand-tint/40 dark:border-slate-700 dark:bg-slate-950 dark:hover:border-brand' => ! $disabledCard,
                                    'cursor-not-allowed border-dashed border-line bg-surface-alt opacity-60 dark:border-slate-800 dark:bg-slate-900' => $disabledCard,
                                ])
                            >
                                {{-- LEFT: the name, and one meta line under it. Prompt 225 compacted the card
                                     from three stacked rows (~90px) to a name plus a meta line (~64px in list
                                     view) — the owner asked for "compact, maybe not as much as this design",
                                     so the density comes from padding and type scale and NOT from dropping
                                     facts: every figure the 90px card carried is still here. --}}
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-semibold leading-tight">{{ $g['name'] }}</span>
                                    <span class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] leading-tight text-ink-muted dark:text-slate-400">
                                        <span class="font-semibold text-ink-muted dark:text-slate-300">{{ $g['product_type_label'] }}</span>
                                        @if ($g['strain_type_label'])<span class="font-semibold text-brand dark:text-slate-200">{{ $g['strain_type_label'] }}</span>@endif
                                        <span>THC {{ number_format($g['thc_bp'] / 100, 1) }}%</span>
                                        <span>CBD {{ number_format($g['cbd_bp'] / 100, 1) }}%</span>
                                        @if ($g['cultivation'])<span>{{ $g['cultivation'] }}</span>@endif
                                        @if ($g['price_label'])<span class="font-medium text-brand dark:text-slate-300">{{ $g['price_label'] }}</span>@endif
                                    </span>
                                </span>

                                {{-- RIGHT: price over stock. 216's cover badge and the stock FIGURE are
                                     unchanged — a staff screen carries quantities, and "≈2 días" is the
                                     information the word "bajo" is not. --}}
                                <span @class([
                                    'flex shrink-0 items-center gap-3 text-xs',
                                    'sm:flex-col sm:items-end sm:gap-0.5' => $this->catalogueLayout() === 'list',
                                    'justify-between' => $this->catalogueLayout() === 'grid',
                                ])>
                                    <span class="text-sm font-semibold text-brand tabular-nums dark:text-slate-100">{{ $this->money($g['rate_cents']) }}/{{ $g['is_unit'] ? __('ud') : 'g' }}</span>
                                    <span class="flex items-center gap-1.5 whitespace-nowrap text-ink-muted dark:text-slate-400">
                                        <span class="tabular-nums">{{ $g['is_unit'] ? $g['remaining_units'].' '.__('uds') : $this->grams($g['remaining_cg']) }}</span>
                                        @if ($g['has_batch'] && $g['low_stock'])
                                            <span data-stock-cover="{{ $g['cover']['basis'] }}" class="inline-flex items-center gap-1 text-warning"><span class="h-2 w-2 rounded-full bg-warning"></span>{{ $g['cover_label'] ?? __('Stock bajo') }}</span>
                                        @elseif ($g['has_batch'])
                                            <span class="inline-flex items-center gap-1 text-success"><span class="h-2 w-2 rounded-full bg-success"></span>{{ __('Con lote') }}</span>
                                        @else
                                            <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-slate-400"></span>{{ __('Sin lote') }}</span>
                                        @endif
                                    </span>
                                </span>
                            </button>
                        @empty
                            <p class="col-span-full rounded-xl border border-dashed border-line px-4 py-8 text-center text-sm text-ink-muted dark:border-slate-700 dark:text-slate-400">{{ __('No hay genéticas con precio activo en esta sede.') }}</p>
                        @endforelse
                    </div>
                    @endif
                </section>
            @endif
            </div>

            {{-- ================= CART: fixed. Identity + allowance, basket, commit. ================= --}}
            <aside
                data-cart-column
                class="flex min-h-0 shrink-0 flex-col gap-3 md:w-[19rem] lg:w-[21rem]"
            >
                {{-- TOP — who is being served and what they may still have. Never scrolls away. --}}
                <div class="shrink-0">
                    @include('livewire.counter.partials.member-cart-summary')
                </div>

                {{-- MIDDLE — the basket and the payment apparatus, plus the member detail that informs it
                     (wallet, carencia, sanction, the counter verdict). This is the cart's scroll region:
                     a long basket lengthens THIS, never the page, so the commit below cannot be pushed off.

                     **It has to LOOK scrollable** (prompt 225). The owner: *"I don't like the scrolling on the
                     right-hand side. It's confusing — there's so much info in there. The only part that needs
                     to scroll is the cart, and it should be obvious."* It always WAS the only scrolling part;
                     nothing said so, and content simply stopped at an edge. A visible gutter and a soft top
                     fade say "there is more above", and `overscroll-contain` stops a flick at the end of the
                     basket from scrolling the page behind it. --}}
                <div data-cart-scroll class="counter-scroll-region flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto overscroll-contain pr-1">
                    @if ($member)
                        <section class="rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900">
                        {{-- No photo on file (prompt 157): identity can't be verified against a face that isn't
                             there. The verdict below drives WARN/OVERRIDE enforcement; this is the fix — take it. --}}
                        @unless ($photoUrl)
                            {{-- ONE LINE with its action (prompt 225), not an amber box the height of the
                                 wallet and the carencia put together. It is a nag, not a blocker: the verdict
                                 below drives whatever WARN/OVERRIDE enforcement the sede has configured, and
                                 this is only the fix. Nothing it said was dropped — the sentence is shorter
                                 and the capture control is beside it instead of under it. --}}
                            <div data-photo-nag class="mt-2.5 flex items-center justify-between gap-2 rounded-xl border border-warning/30 bg-warning/5 px-3 py-1.5">
                                <p class="min-w-0 text-[11px] font-medium leading-tight text-warning">{{ __('Sin foto — verifica y súbela') }}</p>
                                <x-counter.photo-capture :member="$member" source="counter" class="shrink-0" />
                            </div>
                        @endunless
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
                                            @php
                                                $isBlock = in_array($rule['mode'], ['BLOCK', 'OVERRIDE'], true);
                                            @endphp
                                            {{-- While the blocked surface is up it states every BLOCKING rule
                                                 in full, so this column carries only what it does not: the
                                                 warnings. Said once, in one place (prompt 199). --}}
                                            @continue($blockedSurface && $isBlock)
                                            @php
                                                // The ACTOR, not just the rule (prompt 211): a remedy must never
                                                // instruct somebody to do something they hold no permission for, so
                                                // the wording changes with who is reading it and not only the button.
                                                $remedy = \App\Support\VerdictRemedy::describe($rule, $member, $location, auth()->user());
                                            @endphp
                                            {{-- Prompt 135: name the rule in the member's terms + attach the fix; WARN vs BLOCK distinct. --}}
                                            <div @class([
                                                'flex items-start justify-between gap-3 rounded-xl border px-3 py-2 text-sm',
                                                'border-error/30 bg-error/10 text-error' => $isBlock,
                                                'border-warning/30 bg-warning/10 text-warning' => ! $isBlock,
                                            ])>
                                                <span class="min-w-0">
                                                    {{ $remedy['detail'] }}
                                                    @if ($remedy['remedy'])
                                                        <span class="mt-0.5 block text-[11px]">{{ $remedy['remedy'] }}</span>
                                                    @endif
                                                </span>
                                                <span class="shrink-0 rounded-full border border-current px-2 py-0.5 text-[10px] font-semibold uppercase">{{ $isBlock ? __('Bloquea') : __('Aviso') }}</span>
                                            </div>
                                        @endforeach
                                        {{-- While the blocked SURFACE is up it states the block and carries the
                                             resolutions, so this column says neither a second time (prompt 199:
                                             once, in one place). With warnings only — nothing blocking — this
                                             is still where the fix belongs, beside the verdict that named it. --}}
                                        @unless ($blockedSurface)
                                            {{-- The reported dead end, closed where it is read (prompt 211):
                                                 203's own enrol/renew panel, from the one shared partial, on
                                                 the screen that was telling the operator to go somewhere they
                                                 cannot. --}}
                                            @include('livewire.counter.partials.membership-fix')
                                            @include('livewire.counter.partials.inline-fee')
                                        @endunless
                                    </div>
                                @endif
                            </div>
                        @endif
                        </section>
                    @endif

                {{-- WHAT THE CART HAS IN IT — read once, so no section can gate on somebody else's emptiness
                     (prompt 224). That is exactly how bar lines came to be held server-side and rendered
                     nowhere: the bar section, and the tender under it, were nested inside "the DISPENSATION
                     basket has lines". Before 212 that held by construction — the bar quick-add chips lived
                     inside the same block, so a bar line could not exist without a flower line. 212 moved bar
                     browsing to the centre pane, reachable with an empty flower basket, and this gate was
                     never updated. Taps added real lines to a basket the screen never showed. --}}
                @php
                    $hasDispensationLines = ! empty($basketLines);
                    $hasBarLines = ! empty($barLines);
                    $hasAnyLines = $hasDispensationLines || $hasBarLines;
                    // The bar section also appears while the operator is BROWSING the bar, so the first tap
                    // lands somewhere visible rather than into a section that does not exist yet.
                    $showBarSection = $barEnabled && ($hasBarLines || $catalogueSource === 'bar' || $hasDispensationLines);
                @endphp

                <section data-cart-dispensation-section class="rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between">
                        <h2 class="text-base font-semibold">{{ __('Cesta') }}</h2>
                        @if (! empty($basketLines))
                            <button type="button" wire:click="clearBasket" class="inline-flex h-11 min-w-[2.75rem] items-center justify-center rounded-lg px-3 text-sm text-ink-muted hover:bg-black/5 dark:text-slate-400 dark:hover:bg-white/5">{{ __('Vaciar') }}</button>
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
                                    <button type="button" wire:click="removeLine({{ $line['index'] }})" aria-label="{{ __('Quitar de la cesta') }}" class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-ink-muted hover:bg-black/5 dark:text-slate-400 dark:hover:bg-white/5">✕</button>
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
                         what is SHOWN; once shown, blocked controls still stay clickable and explain (prompt 60).

                         **The intent was never wrong — it was wrong about WHOSE emptiness counts** (prompt
                         224). Each section now gates on its own contents and the payment apparatus on either
                         side having lines, so "no payment form for an empty visit" still holds exactly. --}}
                    @if ($hasAnyLines)
                    {{-- Total. Labelled *aportación* deliberately — this half of the visit is a shared-cost
                         contribution and never a sale, and the bar section below is labelled as the sale it
                         is. Two sections, two ledgers, one settle (prompt 118, unchanged by 212).

                         On the DISPENSATION basket, not on "the cart has something in it": a bar-only visit
                         has no aportación and must not be shown a total for one. --}}
                    @if ($hasDispensationLines)
                        <div class="mt-3 flex items-center justify-between rounded-xl bg-surface-alt px-4 py-3 dark:bg-slate-800">
                            <span class="font-semibold">{{ __('Total aportación') }}</span>
                            <span class="text-lg font-bold tabular-nums">{{ $this->money($basketTotalCents) }}</span>
                        </div>
                    @endif

                    {{-- Bar/merch side of the SAME visit (prompt 118): add articles, then settle the whole visit
                         once — one payment, but a dispensation AND a bar order on their separate ledgers. Only
                         where the sede runs a bar. The shared tender below covers the combined total. --}}
                    @if ($showBarSection)
                        <div data-cart-bar-section class="mt-3 rounded-xl border border-line p-3 dark:border-slate-700">
                            <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted dark:text-slate-400">{{ __('Barra y tienda (misma visita)') }}</p>

                            @if (! empty($barLines))
                                <ul class="mt-2 divide-y divide-line dark:divide-slate-800">
                                    @foreach ($barLines as $line)
                                        <li class="flex items-center justify-between gap-2 py-1.5 text-sm">
                                            <span>{{ $line['qty'] }}× {{ $line['name'] }}</span>
                                            <span class="flex items-center gap-2 tabular-nums">
                                                {{ $this->money($line['line_total_cents']) }}
                                                <button type="button" wire:click="removeBarItem({{ $line['index'] }})" aria-label="{{ __('Quitar') }}" class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-ink-muted hover:bg-black/5 dark:text-slate-400 dark:hover:bg-white/5">✕</button>
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            {{-- The chip list stood here and is GONE (prompt 212). It rendered EVERY active
                                 in-stock article at the sede as a `+ Name` chip — uncapped, no search, no
                                 category, no price, no stock — in the one column already carrying the member,
                                 the basket, the tender and the commit. Five looked fine; forty is the same
                                 code. Browsing lives in the centre pane now, where it can. --}}
                            {{-- Two different empty states, because they answer two different questions: on the
                                 dispensario source the operator has to be told WHERE the bar is, and on the
                                 barra source they are already there and need to know the tap will land. --}}
                            @unless ($hasBarLines)
                                <p class="mt-2 text-xs text-ink-muted dark:text-slate-400">
                                    {{ $catalogueSource === 'bar'
                                        ? __('Toca un artículo para añadirlo a esta visita.')
                                        : __('Cambia a Barra arriba para añadir artículos a esta visita.') }}
                                </p>
                            @endunless

                            @if ($hasBarLines)
                                {{-- The bar's own total, stated on its own line: a bar-only visit had no total
                                     anywhere on screen, because the only one rendered was the aportación's
                                     (prompt 224). --}}
                                <div class="mt-2 flex items-center justify-between border-t border-line pt-2 text-sm dark:border-slate-700">
                                    <span class="font-semibold">{{ __('Total barra y tienda') }}</span>
                                    <span class="font-bold tabular-nums">{{ $this->money($barTotalCents) }}</span>
                                </div>

                                <button type="button" wire:click="settleWithBar" data-settle-visit wire:loading.attr="disabled" wire:target="settleWithBar" x-bind:disabled="! online" class="mt-3 h-12 w-full rounded-xl bg-brand text-base font-semibold text-white transition hover:bg-brand-dark focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:opacity-60">
                                    {{ $hasDispensationLines
                                        ? __('Liquidar visita · :total', ['total' => $this->money($basketTotalCents + $barTotalCents)])
                                        : __('Cobrar barra · :total', ['total' => $this->money($barTotalCents)]) }}
                                </button>
                            @endif
                        </div>
                    @endif

                    {{-- Price override (prompt 64): permission-gated, reasoned. Comp defective product or a
                         €0 give-away. Leaving the amount blank charges the resolved price.

                         **Behind one deliberate tap since prompt 213**, and prompt 91 is the reason: it
                         settled that a consequential action *"must not be the loudest control on a tablet
                         being scrolled mid-shift"* and demoted the till close-out accordingly. This rewrites
                         what a member is charged — it is recorded, with a reason, precisely because it
                         matters — and it was sitting open in the ordinary flow, above the commit, on every
                         transaction. Two costs: it invites use, and it is a free-text PRICE field an operator
                         scrolls past hundreds of times a shift with a live basket. The void on this same
                         screen already does this correctly.

                         **Nothing about who may override, what is recorded, or the reason requirement
                         changes** — this is where the control sits, not what it does. The fields are absent
                         from the DOM until opened, so they are not in the tab order either. --}}
                    {{-- Dispensation-only apparatus (prompt 224): a price override rewrites what the member is
                         charged for the APORTACIÓN, and the pad captures their signature for it. Neither has
                         anything to say about a tin of tobacco, so both follow the flower basket. --}}
                    @if ($hasDispensationLines)
                    @can('dispensation.price.override')
                        <div x-data="{ open: false }" class="mt-3">
                            <button
                                type="button"
                                x-on:click="open = ! open"
                                x-bind:aria-expanded="open ? 'true' : 'false'"
                                data-price-override-toggle
                                class="inline-flex min-h-11 w-full items-center justify-between gap-2 rounded-xl border border-line px-4 text-sm font-medium text-ink-muted transition hover:bg-surface-alt dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800"
                            >
                                <span>{{ __('Ajustar precio (queda registrado)') }}</span>
                                <span aria-hidden="true" x-text="open ? '\u25b4' : '\u25be'"></span>
                            </button>

                            <template x-if="open">
                        <div data-price-override class="mt-2 rounded-xl border border-warning/30 bg-warning/5 p-3">
                            <p class="block text-xs font-medium text-warning">{{ __('Ajustar precio (queda registrado)') }}</p>
                            <div class="mt-1 grid gap-2 sm:grid-cols-2">
                                <input type="text" inputmode="decimal" wire:model.blur="priceOverrideEuros" autocomplete="off" placeholder="{{ __('Nuevo total (€)') }}" class="h-11 w-full rounded-xl border border-line bg-surface px-3 text-base text-ink placeholder:text-ink-muted focus:border-warning focus:outline-none focus:ring-2 focus:ring-warning/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                                <input type="text" wire:model.blur="priceOverrideReason" autocomplete="off" placeholder="{{ __('Motivo (p. ej. producto defectuoso)') }}" class="h-11 w-full rounded-xl border border-line bg-surface px-3 text-base text-ink placeholder:text-ink-muted focus:border-warning focus:outline-none focus:ring-2 focus:ring-warning/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                            </div>
                            <p class="mt-1 text-[11px] text-ink-muted dark:text-slate-400">{{ __('Deja el importe vacío para cobrar el precio normal. 0 € = gratis.') }}</p>
                        </div>
                            </template>
                        </div>
                    @endcan
                    @endif

                    {{-- Tender (prompt 74): wallet APPLIED + physical cash TENDERED → change. The cash field is
                         what the member handed over, never the charge; the recorded contribution is the total.
                         Rendered for EITHER basket, and the figures are the COMBINED ones — the split the
                         settle actually applies (prompt 224). --}}
                    <div class="mt-4 space-y-3">
                        <div>
                            <label for="wallet" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Monedero (€)') }}</label>
                            <input id="wallet" type="text" inputmode="decimal" wire:model.live.debounce.400ms="walletInput" @disabled($member === null) autocomplete="off" placeholder="0,00" class="mt-1 h-11 w-full rounded-xl border border-line bg-surface px-3 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                            @if ($member === null)
                                <p class="mt-1 text-[11px] text-ink-muted dark:text-slate-400">{{ __('Atribuye un socio para pagar con monedero.') }}</p>
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

                    {{-- Signature (only when the sede requires it). Prompt 220 extracted the pad to
                         `x-counter.signature-pad` — same markup, same Alpine behaviour, same vault path; it is
                         a component now because it has a second consumer (the application form).

                         With the dispensation basket, not the bar's: it is the signature for the aportación,
                         and `settleWithBar` asks for it only when a dispensation is being written. --}}
                    @if ($requireSignature && $hasDispensationLines)
                        <div class="mt-4 border-t border-line pt-4 dark:border-slate-800">
                            <x-counter.signature-pad
                                capture="saveSignature"
                                clear="clearSignature"
                                :stored="(bool) $signaturePath"
                                :label="__('Firma del socio')"
                                class="mt-0"
                            />
                        </div>
                    @endif

                    {{-- Override (permissioned + reasoned). A dispensation limit, so it follows that basket. --}}
                    @if ($requireOverride && $hasDispensationLines)
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

                    {{-- Prompt 60's colocated block stood here and was REMOVED by prompt 199: it rendered
                         only when the basket was non-empty, so an empty-basket refusal still had to travel
                         to the page top, while a refusal WITH a basket rendered twice. The surviving block
                         sits with the commit action below and covers every basket state. --}}

                    @else
                        {{-- BOTH baskets empty: no heavy payment apparatus (tender, signature, breakdown), just
                             the next step. The commit stays below (prompt 60), it simply has nothing to charge
                             yet. --}}
                        <p data-empty-basket-hint class="mt-3 rounded-xl border border-dashed border-line px-4 py-6 text-center text-sm text-ink-muted dark:border-slate-700 dark:text-slate-400">
                            {{ __('Identifica a un socio y añade una genética para empezar.') }}
                        </p>
                    @endif
                </section>

                {{-- Just committed → receipt + void affordance. --}}
                @if ($lastDispensationId)
                    <section class="rounded-2xl border border-line bg-surface-alt p-4 dark:border-slate-700 dark:bg-slate-800/50">
                        {{-- A label over the receipt and the void, not a second confirmation — see BarPos and
                             prompt 202. The one that announces the commit is beside the commit. --}}
                        <p class="text-sm font-semibold text-ink-muted dark:text-slate-400">{{ __('Última dispensación') }}</p>
                        <div class="mt-3 flex flex-col gap-2">
                            <a href="{{ route('counter.pos.receipt', $lastDispensationId) }}" target="_blank" rel="noopener" class="inline-flex h-11 items-center justify-center rounded-xl border border-line bg-surface px-4 text-sm font-semibold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">{{ __('Ver / imprimir recibo') }}</a>

                            <button type="button" wire:click="emailReceipt" wire:loading.attr="disabled" wire:target="emailReceipt" class="inline-flex h-11 items-center justify-center rounded-xl border border-line bg-surface px-4 text-sm font-semibold text-ink transition hover:bg-surface-alt disabled:opacity-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">{{ __('Enviar comprobante por email') }}</button>

                            @if ($canVoid)
                                <div class="rounded-xl border border-line bg-surface p-3 dark:border-slate-700 dark:bg-slate-900">
                                    <label class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Anular esta dispensación') }}</label>
                                    <textarea wire:model="voidReason" rows="2" placeholder="{{ __('Motivo de la anulación (queda registrado)') }}" class="mt-1 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm focus:border-error focus:outline-none focus:ring-2 focus:ring-error/30 dark:border-slate-700 dark:bg-slate-950"></textarea>
                                    <button type="button" wire:click="voidLast" wire:confirm="{{ __('¿Anular la dispensación? Se revertirán stock y monedero.') }}" class="mt-2 h-11 w-full rounded-lg border border-error/40 bg-error/10 text-sm font-semibold text-error transition hover:bg-error/20">{{ __('Anular') }}</button>
                                </div>
                            @endif
                        </div>
                    </section>
                @endif
                </div>

                {{-- BOTTOM — the commit, at the foot of the column. Fixed, so it is on screen with an
                     empty basket, a full one, and after the selection pane has been scrolled to its end. --}}
                <div class="shrink-0">
                    {{-- The answer to "I pressed Registrar aportación", beside the control (prompts 193/199).
                         Unlike prompt 60's block it does not depend on the basket, so an empty-basket refusal
                         lands here too instead of 700px up the page. --}}
                    @include('livewire.counter.partials.counter-flash', ['anchor' => 'data-commit-feedback'])

                    {{-- Commit — ALWAYS shown and disabled ONLY when offline (prompt 60). Every other blocked
                         state (no socio, empty basket, a hard block, missing signature) stays CLICKABLE, and
                         commit() flashes its reason into the block above — never a silent dead control. --}}
                    <button
                        type="button"
                        wire:click="commitDispensation"
                        data-commit-action
                        wire:loading.attr="disabled"
                        wire:target="commitDispensation"
                        x-bind:disabled="! online"
                        class="mt-4 h-16 w-full rounded-xl bg-brand text-lg font-bold text-white transition hover:bg-brand-dark focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{-- THE TOTAL ON THE BUTTON (prompt 225). The figure and the act are one thing at the
                             moment of pressing it, and the operator reads the total last — from the button
                             they are already looking at, not from a panel above it that may have scrolled. --}}
                        <span wire:loading.remove wire:target="commitDispensation">
                            {{ $basketTotalCents > 0
                                ? __('Registrar aportación · :total', ['total' => $this->money($basketTotalCents)])
                                : __('Registrar aportación') }}
                        </span>
                        <span wire:loading wire:target="commitDispensation">{{ __('Registrando…') }}</span>
                    </button>

                    {{-- Prompt 60's observable refusal, now COLOCATED BY CONSTRUCTION (prompt 225): the reason
                         the press will fail sits under the control that will fail, in amber — a state to
                         resolve, never red, which this project reserves for destructive.

                         Once, and only here: the blocked SURFACE states the rules in full, so this is the
                         one-line reminder beside the button and not a second list (prompt 199). --}}
                    @if ($blockedSurface)
                        {{-- `dark:bg-slate-800` is not decoration. The palette's dark surfaces come from
                             explicit `dark:` utilities, not from a token swap on `--color-surface`, so a
                             `bg-warning/10` with no dark override composites the DARK amber (#d97706) over a
                             LIGHT base. Over slate-800 the same text computes to 4.49:1 — under AA by a
                             hundredth — so it sits on slate-900, where it is 5.3:1. The audit's amber-ramp
                             finding, met by measuring rather than by assuming. --}}
                        <p data-commit-blocked-reason class="mt-2 rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-center text-xs font-semibold text-warning dark:bg-slate-900">
                            {{ __('Bloqueado: resuelve el motivo para poder registrar.') }}
                        </p>
                    @endif
                </div>
            </aside>
        </div>
    @endif
@endif
</div>

{{-- Prompt 23: flag unsaved counter work so the header's Administración / Log out controls confirm before
     leaving. `dirty` only — NOT `volatile` (prompt 206): this basket is session-backed (PersistsBasket), so
     it survives the trip to the hub and back, and Home must not warn about a loss that cannot happen. --}}
@script
<script>
    const sync = () => { if (window.Alpine?.store('counter')) window.Alpine.store('counter').dirty = (($wire.basket?.length ?? 0) > 0); };
    $wire.$watch('basket', sync);
    sync();
</script>
@endscript
