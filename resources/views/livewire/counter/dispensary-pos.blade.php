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
    @include('livewire.counter.partials.photo-check') {{-- prompt 348 — a scanned card waits for the photo check --}}

    @if (! $this->handoverActive())

    {{-- Prompt 175 — the four preconditions resolved to ONE, in dependency order. The operator step is in the
         chain (so till and member cannot jump it) but is rendered by 173's surface, never here. --}}
    @php
        $blocker = \App\Support\CounterBlocker::first([
            \App\Support\CounterBlocker::SEDE => ! $noLocation,
            \App\Support\CounterBlocker::OPERATOR => $this->hasOperator(),
            // No TILL key (prompt 236): RequireOpenTill redirects to the open-till screen before this
            // renders, so an unmet till step never reaches this chain and the card is gone with it.
            \App\Support\CounterBlocker::MEMBER => $member !== null,
        ]);
    @endphp

    {{-- Above the branch on purpose: losing the connection, or the reason a commit was refused, must reach the
         operator whichever state the screen is in. A blocking state replaces the work, not the warnings. --}}
    @if (! $noLocation)
        {{-- Offline banner — unmistakable, fail closed. --}}
        <div x-show="! online" x-cloak role="alert" aria-live="assertive" class="mb-4 flex items-center gap-3 rounded-xl border border-error/40 bg-error/10 px-4 py-3 text-sm font-semibold text-error">
            <x-counter.icon name="alert" class="h-5 w-5" />
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
                icon="map-pin"
                :heading="$mustChooseLocation ? __('Elige tu sede') : __('Sin sede asignada')"
                :body="$mustChooseLocation ? __('Trabajas en varias sedes. Selecciona en la barra superior en cuál estás.') : __('No tienes ninguna sede activa. Pide a un responsable que te asigne una para dispensar.')"
            />
        @else
            {{-- The member step carries its own fix — the lookup itself, not a link elsewhere. Replaced BOTH
                 the grey empty state in the left column and the grey helper text under the commit button,
                 which said the same thing twice in two styles.

                 Prompt 194 — ONE field, the shared one. This blocking state used to stack a scan box above a
                 name box (partials/member-identify), each already accepting what the other asked for. --}}
            <x-counter.blocking-state
                data-blocker="member"
                icon="id-card"
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
        @endphp

        @php
            // A PRESENT but ineligible socio is a blocking state like any other (prompt 225) — it replaces
            // the work rather than sitting beside it. Read once, because three places branch on it: the
            // selection pane, the cart's verdict list (which drops its duplicate copy) and the commit's
            // reason line.
            $blockedSurface = $member !== null && ! empty($hardBlockRules);

            // Prompt 272 — the member-detail card renders only when it has a ROW to show. It used to render
            // whenever the verdict was not clear; while the blocked surface is up every blocking rule is skipped
            // below and the remedies are withheld, so a blocked socio with no warnings and nothing owed got an
            // empty 32px box between the identity and the basket. Same rule the loop applies, computed once.
            $memberDetailRules = ($member && $verdict && ! $verdict->isClear())
                ? collect($verdict->rules)->reject(fn (array $rule): bool => $rule['satisfied'] || ($blockedSurface && in_array($rule['mode'], ['BLOCK', 'OVERRIDE'], true)))
                : collect();
            $showMemberDetail = $member && $verdict && ! $verdict->isClear()
                && ($memberDetailRules->isNotEmpty() || ($owesCents ?? 0) > 0);
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
                {{-- Prompt 299 — no search once a socio is chosen. It stayed "so an operator can scan the next socio
                     without clearing the current one", and the cost was a screen that looked as if nobody was identified,
                     with the pad pushed down. The next socio is now the member card's scan button (camera) or a card
                     reader, caught at window level below; *Cambiar socio* brings the search back. --}}
                @if ($cardReadersEnabled)
                    <div data-card-wedge x-data="window.cardWedge()" hidden></div>
                @endif
                {{-- Weight entry panel (opens when a genetic is chosen). --}}
                @if ($activeGenetic)
                    {{-- Prompt 292 — the keypad runs in the browser (window.dispensaryPad): no key makes a request; the value
                         reaches the server with "Añadir a la cesta" and is validated there exactly as before. Keyed per
                         genetic so a new strain starts a fresh pad. --}}
                    <section wire:key="weight-entry-{{ $activeGenetic->id }}"
                             x-data="window.dispensaryPad({
                                 value: @js($weightInput),
                                 calc: @js($calculatorMode),
                                 calcEnabled: @js($calculatorEnabled),
                                 weight: @js(! $activeGenetic->isUnitType()),
                                 rateCents: @js($activeGeneticPriceCents),
                                 dailyRemainingCg: @js($limits?->dailyRemainingCg()),
                             })"
                             @keydown.window="onKey($event)"
                             @counter-card-scan.window="undoSince($event.detail.startedAt)"
                             {{-- Prompt 333 — a strain tap brings the pad into view and focus (window.bringIntoView): on
                                  mount (a new strain) and on `weight-entry-opened` (the same strain tapped again). --}}
                             data-weight-entry tabindex="-1"
                             x-init="$nextTick(() => window.bringIntoView($el))"
                             x-on:weight-entry-opened.window="$nextTick(() => window.bringIntoView($el))"
                             class="scroll-mt-2 rounded-2xl border border-brand/40 bg-brand-tint/40 p-4 focus:outline-none dark:border-brand/40 dark:bg-slate-900">
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
                            <button type="button" wire:click="cancelWeightEntry" class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm text-ink-muted hover:bg-black/5 dark:text-slate-400 dark:hover:bg-white/5">{{ __('Cancelar') }}</button>
                        </div>

                        @if (! $activeGenetic->isUnitType())
                        {{-- grams vs calculator (€) toggle — only where the sede has switched the calculator on (292: off by
                             default; the server refuses calculator mode there too). --}}
                        @if ($calculatorEnabled)
                            <div data-calculator-toggle class="mt-3 inline-flex rounded-xl border border-line bg-surface p-0.5 text-sm dark:border-slate-700 dark:bg-slate-950">
                                <button type="button" @click="setMode(false)" x-bind:aria-pressed="calc ? 'false' : 'true'" x-bind:class="calc ? 'text-ink-muted dark:text-slate-400' : 'bg-brand text-white'" class="inline-flex min-h-11 items-center rounded-lg px-3 font-medium">{{ __('Gramos') }}</button>
                                <button type="button" @click="setMode(true)" x-bind:aria-pressed="calc ? 'true' : 'false'" x-bind:class="calc ? 'bg-brand text-white' : 'text-ink-muted dark:text-slate-400'" class="inline-flex min-h-11 items-center rounded-lg px-3 font-medium">{{ __('Calculadora €') }}</button>
                            </div>
                        @endif

                        {{-- display --}}
                        <div class="mt-3 flex items-center justify-between rounded-xl border border-line bg-surface px-4 py-3 dark:border-slate-700 dark:bg-slate-950">
                            <span class="text-sm text-ink-muted dark:text-slate-400" x-text="calc ? @js(__('Aportación (€)')) : @js(__('Peso (g)'))">{{ $calculatorMode && $calculatorEnabled ? __('Aportación (€)') : __('Peso (g)') }}</span>
                            <span data-weight-display class="text-2xl font-bold tabular-nums" x-text="(value === '' ? '0' : value) + (calc ? ' €' : ' g')">{{ $weightInput === '' ? '0' : $weightInput }}{{ $calculatorMode && $calculatorEnabled ? ' €' : ' g' }}</span>
                        </div>

                        {{-- One-tap weight presets (prompt 133): each shows its resulting price; 3,5 g shows the
                             eighth break. A preset over the member's remaining allowance is shown unavailable, not
                             refused after the tap. It only FILLS the weight input — the same checks apply at add. --}}
                        @if (! empty($weightPresets))
                            <div class="mt-3 grid grid-cols-4 gap-2" data-weight-presets x-show="! calc">
                                @foreach ($weightPresets as $preset)
                                    <button
                                        type="button"
                                        @if ($preset['available']) @click="preset({{ $preset['grams_cg'] }}, @js($preset['label']))" @else disabled aria-disabled="true" @endif
                                        data-weight-preset="{{ $preset['grams_cg'] }}"
                                        @class([
                                            'flex min-h-11 flex-col items-center justify-center rounded-xl border px-1 py-1 text-sm font-semibold transition',
                                            'border-line bg-surface text-ink hover:bg-brand-tint hover:text-brand dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:hover:bg-slate-800 dark:hover:text-white' => $preset['available'],
                                            'cursor-not-allowed border-line/60 text-ink-muted opacity-40 dark:border-slate-800' => ! $preset['available'],
                                        ])
                                    >
                                        <span>{{ $preset['label'] }} g</span>
                                        @if ($preset['price_cents'] !== null)
                                            <span @class(['text-[11px] font-medium', 'font-semibold text-brand dark:text-slate-100' => $preset['eighth_applied'], 'text-ink-muted dark:text-slate-400' => ! $preset['eighth_applied']])>
                                                {{ $this->money($preset['price_cents']) }}@if ($preset['eighth_applied']) · ⅛@endif
                                            </span>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        {{-- numeric pad --}}
                        <div class="mt-3 grid grid-cols-3 gap-2" data-weight-pad>
                            @foreach (['1','2','3','4','5','6','7','8','9'] as $digit)
                                <button type="button" @click="push('{{ $digit }}')" class="h-14 rounded-xl border border-line bg-surface text-xl font-semibold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:hover:bg-slate-800">{{ $digit }}</button>
                            @endforeach
                            <button type="button" @click="push('.')" aria-label="{{ __('Punto decimal') }}" class="h-14 rounded-xl border border-line bg-surface text-xl font-semibold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:hover:bg-slate-800">.</button>
                            <button type="button" @click="push('0')" class="h-14 rounded-xl border border-line bg-surface text-xl font-semibold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:hover:bg-slate-800">0</button>
                            <button type="button" @click="back()" aria-label="{{ __('Retroceso') }}" class="h-14 rounded-xl border border-line bg-surface text-xl font-semibold text-ink-muted transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-400 dark:hover:bg-slate-800">⌫</button>
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

                        {{-- Gram-equivalent + real-time ceiling feedback. A UNIT count is the server's (units × grams per unit);
                             a WEIGHT entry is the browser's mirror of the SAME resolver (prompt 292 — it used to read typed
                             euros as grams). The data-* carry the server's figures for the entry it knows. --}}
                        @if ($activeGenetic->isUnitType())
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
                                            <span class="font-medium {{ \App\Support\LimitSnapshot::dailyStateText($limits->dailyRemainingState($remainingAfter)) }}">{{ $this->grams(max(0, $remainingAfter)) }}</span>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        @else
                            @php $serverRemainingAfter = ($limits && $activeEntryGramsCg !== null) ? $limits->dailyRemainingCg() - $activeEntryGramsCg : null; @endphp
                            <div data-entry-preview data-entry-grams="{{ $activeEntryGramsCg ?? '' }}" data-remaining-after="{{ $serverRemainingAfter ?? '' }}" data-daily-limit="{{ $limits?->dailyLimitCg ?? '' }}"
                                 x-show="enteredCg !== null && enteredCg > 0" x-cloak
                                 class="mt-3 rounded-xl border border-line bg-surface px-4 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-950">
                                <div class="flex items-center justify-between">
                                    <span class="text-ink-muted dark:text-slate-400">{{ __('Equivale a') }}</span>
                                    <span data-entry-preview-grams class="font-semibold tabular-nums" x-text="enteredCg !== null ? grams(enteredCg) : ''"></span>
                                </div>
                                @if ($limits) {{-- none at all while limits are switched off (296) --}}
                                <div class="mt-1 flex items-center justify-between text-xs" x-show="remainingAfter !== null">
                                    <span class="text-ink-muted dark:text-slate-400">{{ __('Restante hoy tras esta entrada') }}</span>
                                    <span data-entry-preview-remaining class="font-medium" x-bind:class="remainingAfter <= 0 ? 'text-error' : (($el.closest('[data-daily-limit]')?.dataset.dailyLimit ?? 0) > 0 && remainingAfter * 4 <= $el.closest('[data-daily-limit]').dataset.dailyLimit ? 'text-warning' : 'text-success')" x-text="remainingAfter !== null ? grams(remainingAfter) : ''"></span>
                                </div>
                                @endif
                            </div>
                        @endif

                        {{-- Prompt 250 — AUTOMATIC: no Lote row. Everything of a genetic is one jar; the system
                             draws oldest-first at commit. The pane shows only the sede's dispensable total. --}}
                        @if ($this->automaticBatches())
                            <div class="mt-3" data-batch-mode="automatic">
                                <p class="text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('En stock: :total', ['total' => $this->activeGeneticStockLabel() ?? '—']) }}</p>
                            </div>
                        @else
                            {{-- MANUAL — batch selector (FEFO default, overridable to another dispensable batch). --}}
                            <div class="mt-3" data-batch-mode="manual">
                                <p class="text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Lote') }}</p>
                                @if ($activeGeneticBatches->isEmpty())
                                    <p class="mt-1 rounded-lg border border-error/30 bg-error/10 px-3 py-2 text-sm text-error">{{ __('Sin lote disponible (agotado o caducado).') }}</p>
                                @else
                                    <div class="mt-1 flex flex-wrap gap-2">
                                        @foreach ($activeGeneticBatches as $i => $batch)
                                            <x-counter.batch-chip :batch="$batch" :selected="$activeBatchId === $batch->id" :fefo="$i === 0"
                                                :quantity="$activeGenetic->isUnitType() ? $batch->remaining_units.' '.__('uds') : $this->grams($batch->remaining_cg?->centigrams ?? 0)" />
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endif

                        {{-- One request in flight: only this button shows a loading state, and a double tap adds one line. --}}
                        {{-- Prompt 358 — the same pad edits a basket line (tap the line): the button then says so. --}}
                        <x-button size="lg" class="mt-4 w-full" data-add-line @click="add()" x-bind:disabled="adding" x-bind:aria-busy="adding">{{ $editingLine !== null ? __('Actualizar') : __('Añadir a la cesta') }}</x-button>
                    </section>
                @endif

                {{-- THE CATALOGUE PANE — a Livewire island (prompt 293). It is re-sent only when what it shows has changed
                     (RendersIslandsOnChange: prices for this socio, stock, the sellable set), so a basket, member or
                     payment tap no longer returns every card on the page. Inside it nothing talks to the server except a
                     tap on a card, and that goes through `$wire.…` rather than `wire:click` ON PURPOSE: a `wire:` action on
                     an element inside an island re-renders only the island, and choosing a genetic has to open the weight
                     entry and the cart outside it. The tab, the filters, the search and the layout are `data-view-only` —
                     Alpine state over the full catalogue, zero requests — and a structural test refuses a `wire:` there. --}}
                <section
                    data-catalogue
                    x-data="window.counterCatalogue(@js([
                        'source' => 'genetics',
                        'layouts' => ['genetics' => $geneticLayout, 'bar' => $articleLayout],
                        'layoutProps' => ['genetics' => 'geneticLayout', 'bar' => 'articleLayout'],
                    ] + $this->catalogueSort()))"
                    class="rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900"
                >
                    @island('catalogue-header', always: $this->islandChanged('header'))
                    @php
                        $head = $this->islandView('header');
                        $sources = $head['barEnabled']
                            ? ['genetics' => __('Dispensario'), 'bar' => __('Barra')]
                            : ['genetics' => __('Dispensario')];
                        $chipOn = 'border-brand bg-brand text-white';
                        $chipOff = 'border-line text-ink-muted dark:border-slate-700 dark:text-slate-400';
                        $toggleOn = 'bg-brand text-white';
                        $toggleOff = 'text-ink-muted hover:bg-surface-alt dark:text-slate-400 dark:hover:bg-slate-800';
                    @endphp
                    {{-- Prompt 176: stacks until lg. At 820 portrait the selection pane is ~470px and
                         title + view toggle + search do not fit on one row without clipping. --}}
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        {{-- THE TOGGLE IS THE HEADING (prompt 212): it names what you are browsing, and it is the control
                             you came to press. It changes what you BROWSE, never which basket you fill — a genetic tap still
                             opens the weight entry, an article tap still adds a bar line, on their separate ledgers (118). A
                             sede with no bar, or an operator without `pos.bar` (266), has no bar source at all. --}}
                        <div role="group" aria-label="{{ __('Qué estás mirando') }}" x-bind:data-catalogue-source="source" data-catalogue-source="genetics"
                             class="flex w-fit shrink-0 gap-1 self-start rounded-xl border border-line p-1 dark:border-slate-700">
                            @foreach ($sources as $source => $label)
                                <button
                                    type="button"
                                    data-view-only
                                    data-source-option="{{ $source }}"
                                    x-on:click="setSource('{{ $source }}')"
                                    aria-pressed="{{ $source === 'genetics' ? 'true' : 'false' }}"
                                    x-bind:aria-pressed="source === '{{ $source }}' ? 'true' : 'false'"
                                    class="inline-flex min-h-11 items-center rounded-lg px-4 text-base font-semibold transition {{ $source === 'genetics' ? $toggleOn : $toggleOff }}"
                                    x-bind:class="{ '{{ $toggleOn }}': source === '{{ $source }}', '{{ $toggleOff }}': ! (source === '{{ $source }}') }"
                                >{{ $label }}</button>
                            @endforeach
                        </div>
                        {{-- List / grid, and beside it 351's order — one row, so the portrait toolbar grows by nothing. --}}
                        <div class="flex shrink-0 flex-wrap gap-2 self-start">
                        {{-- List / grid — one remembered choice PER SOURCE (225): list for genetics, grid for the bar by default. --}}
                        <div role="group" aria-label="{{ __('Vista') }}" class="flex w-fit shrink-0 gap-1 self-start rounded-xl border border-line p-1 dark:border-slate-700">
                            @foreach ([['list', __('Lista'), 'list'], ['grid', __('Cuadrícula'), 'grid']] as [$mode, $label, $glyph])
                                <button
                                    type="button"
                                    data-view-only
                                    data-layout-option="{{ $mode }}"
                                    x-on:click="setLayout('{{ $mode }}')"
                                    aria-label="{{ $label }}"
                                    aria-pressed="{{ $geneticLayout === $mode ? 'true' : 'false' }}"
                                    x-bind:aria-pressed="layoutOf() === '{{ $mode }}' ? 'true' : 'false'"
                                    class="inline-flex h-11 w-11 items-center justify-center rounded-lg text-base transition {{ $geneticLayout === $mode ? $toggleOn : $toggleOff }}"
                                    x-bind:class="{ '{{ $toggleOn }}': layoutOf() === '{{ $mode }}', '{{ $toggleOff }}': ! (layoutOf() === '{{ $mode }}') }"
                                ><x-counter.icon :name="$glyph" class="h-5 w-5" /></button>
                            @endforeach
                        </div>

                        {{-- Prompt 351 — the strain order: €↓ / €↑ / A–Z. Starts on the sede's default; the cards carry their rank
                             in all three orders, so a tap only moves them (CSS `order`) — no request. Remembered on this tablet
                             for the business day. The Barra keeps its own (alphabetical) order. --}}
                        @php $sortStart = $this->catalogueSort()['sortDefault']; @endphp
                        <div role="group" aria-label="{{ __('Orden') }}" data-sort-control x-show="source === 'genetics'"
                             class="flex w-fit shrink-0 gap-1 self-start rounded-xl border border-line p-1 dark:border-slate-700">
                            @foreach ([[\App\Support\DispensarySort::PRICE_DESC, '€↓', __('Precio: de mayor a menor')], [\App\Support\DispensarySort::PRICE_ASC, '€↑', __('Precio: de menor a mayor')], [\App\Support\DispensarySort::ALPHA, 'A–Z', __('Alfabético')]] as [$order, $glyph, $label])
                                <button
                                    type="button"
                                    data-view-only
                                    data-sort-option="{{ $order }}"
                                    x-on:click="setSort('{{ $order }}')"
                                    aria-label="{{ $label }}"
                                    title="{{ $label }}"
                                    aria-pressed="{{ $sortStart === $order ? 'true' : 'false' }}"
                                    x-bind:aria-pressed="sort === '{{ $order }}' ? 'true' : 'false'"
                                    class="inline-flex h-11 min-w-11 items-center justify-center rounded-lg px-3 text-sm font-semibold tabular-nums transition {{ $sortStart === $order ? $toggleOn : $toggleOff }}"
                                    x-bind:class="{ '{{ $toggleOn }}': sort === '{{ $order }}', '{{ $toggleOff }}': ! (sort === '{{ $order }}') }"
                                >{{ $glyph }}</button>
                            @endforeach
                        </div>
                        </div>

                        {{-- One search box per source, each keeping its own term: switching source to check a price and
                             switching back must not clear what you were looking for. NOT a member search (194). --}}
                        <input
                            type="text"
                            data-view-only
                            x-model="search.genetics"
                            data-genetic-search
                            x-on:basket-line-added.window="search.genetics = ''"
                            x-show="source === 'genetics'"
                            aria-label="{{ __('Buscar genética…') }}"
                            autocomplete="off"
                            placeholder="{{ __('Buscar genética…') }}"
                            class="h-11 w-full rounded-xl border border-line bg-surface px-4 text-sm text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 sm:w-56"
                        >
                        @if ($head['barEnabled'])
                            <input
                                type="text"
                                data-view-only
                                data-article-search
                                x-model="search.bar"
                                x-show="source === 'bar'"
                                x-cloak
                                aria-label="{{ __('Buscar producto…') }}"
                                autocomplete="off"
                                placeholder="{{ __('Buscar producto…') }}"
                                class="h-11 w-full rounded-xl border border-line bg-surface px-4 text-sm text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 sm:w-56"
                            >
                            {{-- Prompt 331 — the Bar screen's manual line, on the BARRA tab only: a bar/shop line, never cannabis
                                 (a dispensation names a batch and grams). Opens the shared modal; no request until it adds. --}}
                            <button type="button" x-show="source === 'bar'" x-cloak @click="$dispatch('manual-line-open')" data-misc-open
                                    title="{{ __('Línea manual de barra') }}"
                                    class="inline-flex h-11 shrink-0 items-center gap-1 rounded-xl border border-brand/40 bg-brand-tint/40 px-3 text-sm font-semibold text-brand transition hover:bg-brand-tint dark:border-brand/40 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700">
                                <span aria-hidden="true">＋</span>{{ __('Línea manual') }}
                            </button>
                        @endif
                    </div>

                    {{-- "Their usual" (prompt 133): the member's recent genetics, one tap each, only those sellable at this
                         sede right now. Genetics only (212) — it is built from DISPENSATION history. --}}
                    @if (! empty($head['usual']))
                        <div class="mt-3" data-usual-genetics x-show="source === 'genetics'">
                            <p class="mb-1 text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Su habitual') }}</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($head['usual'] as $usual)
                                    <button
                                        type="button"
                                        x-on:click="window.counterPane.remember(); $wire.chooseGenetic('{{ $usual['id'] }}')"
                                        data-usual-genetic="{{ $usual['id'] }}"
                                        class="inline-flex min-h-11 items-center gap-1.5 rounded-full border border-brand/40 bg-brand-tint px-4 text-sm font-semibold text-brand transition hover:bg-brand hover:text-white dark:bg-slate-800 dark:text-slate-100"
                                    >{{ $usual['name'] }}</button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Prompt 176 — the filters are COLLAPSED by default and search is the primary route (Treez is
                         search-first for the same reason). Each row is LABELLED (66): Categoría, Tipo and Variedad are
                         different axes. The bar has one row, Categoría (212) — type and strain are facts about cannabis. --}}
                    @php
                        $filterRows = [
                            ['genetics', 'category', __('Categoría'), __('Todas'), array_map(fn (array $c): array => [$c['id'], $c['name']], $head['categories'])],
                            ['genetics', 'productType', __('Tipo'), __('Todos los tipos'), array_map(fn (array $t): array => [$t['value'], $t['label']], $head['productTypes'])],
                            ['genetics', 'strainType', __('Variedad'), __('Todas'), array_map(fn (array $t): array => [$t['value'], $t['label']], $head['strainTypes'])],
                            ['bar', 'category', __('Categoría'), __('Todas'), array_map(fn (array $c): array => [$c['id'], $c['name']], $head['articleCategories'])],
                        ];
                    @endphp
                    <div class="mt-3">
                        <button
                            type="button"
                            x-on:click="filtersOpen = ! filtersOpen"
                            x-bind:aria-expanded="filtersOpen ? 'true' : 'false'"
                            aria-expanded="false"
                            class="inline-flex h-11 items-center gap-2 rounded-xl border border-line px-4 text-sm font-medium text-ink-muted transition hover:bg-surface-alt dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800"
                        >
                            {{ __('Filtros') }}
                            <span x-show="activeFilters > 0" x-cloak x-text="activeFilters" class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-brand px-1.5 text-[11px] font-bold text-white"></span>
                            <span aria-hidden="true" x-text="filtersOpen ? '▴' : '▾'">&#9662;</span>
                        </button>

                        <div x-show="filtersOpen" x-cloak>
                            @foreach ($filterRows as [$rowSource, $axis, $heading, $allLabel, $options])
                                @continue(empty($options))
                                @php $state = $axis === 'category' ? "category.{$rowSource}" : $axis; @endphp
                                <div class="mt-2" x-show="source === '{{ $rowSource }}'">
                                    <p class="mb-1 text-xs font-medium text-ink-muted dark:text-slate-400">{{ $heading }}</p>
                                    <div role="group" aria-label="{{ $heading }}" class="flex flex-wrap gap-2">
                                        @foreach ([[null, $allLabel], ...$options] as [$value, $label])
                                            <button
                                                type="button"
                                                data-view-only
                                                x-on:click="filter('{{ $axis }}', @js($value))"
                                                aria-pressed="{{ $value === null ? 'true' : 'false' }}"
                                                x-bind:aria-pressed="{{ $state }} === @js($value) ? 'true' : 'false'"
                                                class="inline-flex min-h-11 items-center rounded-full border px-4 text-sm {{ $value === null ? $chipOn : $chipOff }}"
                                                x-bind:class="{ '{{ $chipOn }}': {{ $state }} === @js($value), '{{ $chipOff }}': ! ({{ $state }} === @js($value)) }"
                                            >{{ $label }}</button>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    @endisland

                    {{-- THE BAR SOURCE — the same card (230), the same layout toggle, the same 44px floor. A tap adds a BAR
                         line (`addBarItem`), never a dispensation. Stock is a count on a staff screen (216). --}}
                    @island('catalogue-bar', always: $this->islandChanged('bar'))
                    @php $bar = $this->islandView('bar'); @endphp
                    @if ($bar['enabled'])
                        <div x-show="source === 'bar'" x-cloak>
                            <div data-layout="{{ $articleLayout }}" x-bind:data-layout="layoutOf('bar')"
                                 class="mt-4 as-list:flex as-list:flex-col as-list:gap-2 as-grid:grid as-grid:gap-3 as-grid:sm:grid-cols-2">
                                @foreach ($bar['rows'] as $article)
                                    <x-counter.article-card :article="$article" action="addBarItem" :thumbs="$bar['thumbs']"
                                        data-catalogue-item="bar" :search="[$article['name'], (string) ($article['category_name'] ?? '')]" />
                                @endforeach
                            </div>
                            <p x-show="! anyVisible('bar')" x-cloak class="mt-4 rounded-xl border border-dashed border-line px-4 py-6 text-center text-sm text-ink-muted dark:border-slate-700 dark:text-slate-400">
                                {{ empty($bar['rows']) ? __('No hay productos disponibles en esta sede.') : __('Ningún producto coincide con la búsqueda.') }}
                            </p>
                        </div>
                    @endif
                    @endisland

                    {{-- Prompt 331 — the manual bar line's modal: the Bar screen's own partial, outside every island so adding a
                         line renders the cart. --}}
                    @if ($barEnabled)
                        @include('livewire.counter.partials.manual-line-modal', ['heading' => __('Línea manual de barra')])
                    @endif

                    @island('catalogue-genetics', always: $this->islandChanged('genetics'))
                    @php $gen = $this->islandView('genetics'); @endphp
                    <div x-show="source === 'genetics'">
                        <div data-genetic-sort-container data-layout="{{ $geneticLayout }}" x-bind:data-layout="layoutOf('genetics')"
                             class="mt-4 as-list:flex as-list:flex-col as-list:gap-2 as-grid:grid as-grid:gap-3 as-grid:sm:grid-cols-2">
                            {{-- Prompt 271 — the variety's photo, 193's rule: the column only when some variety has one. --}}
                            @foreach ($gen['rows'] as $g)
                                @php $disabledCard = ! $gen['hasMember'] || ! $g['has_batch']; @endphp
                                <button
                                    type="button"
                                    @if (! $disabledCard) x-on:click="window.counterPane.remember(); $wire.chooseGenetic('{{ $g['id'] }}')" @endif
                                    @disabled($disabledCard)
                                    data-product
                                    data-catalogue-item="genetics"
                                    data-category="{{ $g['category_id'] }}"
                                    data-type="{{ $g['product_type'] }}"
                                    data-strain="{{ $g['strain_type'] }}"
                                    data-search="{{ $g['name'] }}"
                                    @foreach ($g['rank'] ?? [] as $order => $rank) data-rank-{{ $order }}="{{ $rank }}" @endforeach
                                    x-bind:style="{ order: rankOf($el) }"
                                    x-show="visible($el)"
                                    @class([
                                        'flex w-full min-h-11 flex-col gap-1 rounded-xl border px-3 py-1.5 text-left transition',
                                        'as-list:sm:flex-row as-list:sm:items-center as-list:sm:justify-between as-list:sm:gap-4',
                                        'border-line bg-surface hover:border-brand hover:bg-brand-tint/40 dark:border-slate-700 dark:bg-slate-950 dark:hover:border-brand dark:hover:bg-slate-800' => ! $disabledCard,
                                        'cursor-not-allowed border-dashed border-line bg-surface-alt opacity-60 dark:border-slate-800 dark:bg-slate-900' => $disabledCard,
                                    ])
                                >
                                    {{-- LEFT: the name and one meta line (225's density, every figure kept). --}}
                                    @if ($gen['thumbs'])
                                        <span data-genetic-thumb class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-surface-alt dark:bg-slate-800">
                                            @if ($g['image_url'])
                                                <img src="{{ $g['image_url'] }}" alt="" class="h-full w-full object-cover">
                                            @else
                                                <span class="text-sm font-semibold text-ink-muted dark:text-slate-400">{{ mb_strtoupper(mb_substr($g['name'], 0, 1)) }}</span>
                                            @endif
                                        </span>
                                    @endif
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-semibold leading-tight">{{ $g['name'] }}</span>
                                        <span class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] leading-tight text-ink-muted dark:text-slate-400">
                                            <span class="font-semibold text-ink-muted dark:text-slate-300">{{ $g['product_type_label'] }}</span>
                                            @if ($g['strain_type_label'])<span class="font-semibold text-brand dark:text-slate-200">{{ $g['strain_type_label'] }}</span>@endif
                                            @if (($g['thc_mg'] ?? null) !== null){{-- prompt 326 — an edible reads by mg --}}
                                                <span data-edible-thc>{{ __(':mg mg THC', ['mg' => $g['thc_mg']]) }}</span>
                                            @else
                                                <span>THC {{ \App\Support\NumberFormat::decimal($g['thc_bp'] / 100, 1) }}%</span>
                                                <span>CBD {{ \App\Support\NumberFormat::decimal($g['cbd_bp'] / 100, 1) }}%</span>
                                            @endif
                                            @if ($g['cultivation'])<span>{{ $g['cultivation'] }}</span>@endif
                                            @if ($g['price_label'])<span class="font-medium text-brand dark:text-slate-300">{{ $g['price_label'] }}</span>@endif
                                        </span>
                                    </span>

                                    {{-- RIGHT: price over stock. 216's cover badge and the stock FIGURE — "≈2 días" is the
                                         information the word "bajo" is not.
                                         Prompt 301 — in GRID view the price and the stock are two left-aligned lines inside the
                                         tile: on one line that could not wrap, "499,00 g ● Con lote" ran past a portrait tile's
                                         edge. The figure and the dot never shrink; the status word truncates as a last resort
                                         and keeps its full text in `title`. List view is unchanged. --}}
                                    @php($statusWord = $g['has_batch'] && $g['low_stock'] ? ($g['cover_label'] ?? __('Stock bajo')) : ($g['has_batch'] ? __('Con lote') : __('Sin lote')))
                                    <span class="flex shrink-0 items-center gap-3 text-xs as-list:sm:flex-col as-list:sm:items-end as-list:sm:gap-0.5 as-grid:w-full as-grid:min-w-0 as-grid:shrink as-grid:flex-col as-grid:items-start as-grid:gap-0.5">
                                        <span class="text-sm font-semibold text-brand tabular-nums dark:text-slate-100">{{ $this->money($g['rate_cents']) }}/{{ $g['is_unit'] ? __('ud') : 'g' }}</span>
                                        <span data-genetic-stock class="flex min-w-0 max-w-full items-center gap-1.5 whitespace-nowrap text-ink-muted dark:text-slate-400">
                                            <span class="shrink-0 tabular-nums">{{ $g['is_unit'] ? $g['remaining_units'].' '.__('uds') : $this->grams($g['remaining_cg']) }}</span>
                                            @if ($g['has_batch'] && $g['low_stock'])
                                                <span data-stock-cover="{{ $g['cover']['basis'] }}" title="{{ $statusWord }}" class="inline-flex min-w-0 items-center gap-1 text-warning"><span class="h-2 w-2 shrink-0 rounded-full bg-warning"></span><span class="truncate">{{ $statusWord }}</span></span>
                                            @elseif ($g['has_batch'])
                                                <span title="{{ $statusWord }}" class="inline-flex min-w-0 items-center gap-1 text-success"><span class="h-2 w-2 shrink-0 rounded-full bg-success"></span><span class="truncate">{{ $statusWord }}</span></span>
                                            @else
                                                <span title="{{ $statusWord }}" class="inline-flex min-w-0 items-center gap-1"><span class="h-2 w-2 shrink-0 rounded-full bg-slate-400"></span><span class="truncate">{{ $statusWord }}</span></span>
                                            @endif
                                        </span>
                                    </span>
                                </button>
                            @endforeach
                        </div>
                        @if (empty($gen['rows']))
                            <p class="mt-4 rounded-xl border border-dashed border-line px-4 py-8 text-center text-sm text-ink-muted dark:border-slate-700 dark:text-slate-400">{{ __('No hay genéticas con precio activo en esta sede.') }}</p>
                        @else
                            <p x-show="! anyVisible('genetics')" x-cloak class="mt-4 rounded-xl border border-dashed border-line px-4 py-8 text-center text-sm text-ink-muted dark:border-slate-700 dark:text-slate-400">{{ __('Ninguna genética coincide con la búsqueda.') }}</p>
                        @endif
                    </div>
                    @endisland
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
                    {{-- Prompt 347 — the person at the PIN is the member being served. Allowed (and flagged on the record) unless
                         this sede requires someone else to serve them; the commit itself enforces that. --}}
                    @if ($this->servingSelf())
                        <p data-self-serving role="status" class="mt-2 rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-sm font-medium text-warning">{{ __('Te estás atendiendo a ti mismo') }}</p>
                    @endif
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
                {{-- `min-h-[9rem]` (prompt 234): the pinned head and foot both grow — the head with the nag,
                     the foot with a flash — and without a floor the basket is what pays for both. The owner:
                     *"not cover the basket like this."* A flash now costs the region nothing below its
                     minimum; it scrolls instead. Measured at both orientations, with and without a flash. --}}
                <div data-cart-scroll x-data="{ atTop: true }" x-on:scroll.passive="atTop = $el.scrollTop <= 0" x-bind:class="atTop && 'at-top'" class="counter-scroll-region flex min-h-[9rem] flex-1 flex-col gap-4 overflow-y-auto overscroll-contain">
                    {{-- The card renders ONLY when the verdict has something an operator must act on. A clean
                         socio's column is now identity (pinned) → basket, with nothing between them. --}}
                    @if ($showMemberDetail)
                        <section data-member-detail class="rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900">
                        {{-- WHAT THIS SECTION NO LONGER SAYS (prompt 234). The owner: *"the waiting period
                             Completed I don't think is needed, along with Cleared to dispense — if there's an
                             issue with the account just block the whole page til it's resolved."* He is
                             describing the principle 225 half-built: **the screen's states speak; the column
                             does not narrate them.** Each deletion maps to where the screen already says it:

                               · the photo nag → moved to the PINNED identity card, at his request
                               · `Monedero`    → moved to the pinned card, at his request
                               · `Carencia · Cumplida` → an ACTIVE carencia is a verdict rule and lands on
                                 225's blocked surface; "Cumplida" is the rule NOT applying — a row about
                                 nothing
                               · `✓ Apto para dispensar.` → the catalogue being present IS the verdict. A
                                 blocked socio has no catalogue (225), so silence is the all-clear
                               · the standalone `Sanción activa` box → the verdict machinery already states a
                                 sanction, at the severity the matrix gives it: blocking on the surface, warn
                                 in the list below. Said twice was 199's rule broken quietly

                             What stays is what needs an operator: the unsatisfied WARN rules and 211's
                             remedies. **A clean socio with a photo now has NOTHING between the pinned
                             identity and the basket** — which is the acceptance test. --}}
                        {{-- Counter verdict (same shared resolver as the door) — only when it has something
                             to say. Silence is the all-clear. --}}
                        @if ($verdict && ! $verdict->isClear())
                            <div>
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
                                        @include('livewire.counter.partials.member-owes')
                                        @unless ($blockedSurface)
                                            {{-- The reported dead end, closed where it is read (prompt 211):
                                                 203's own enrol/renew panel, from the one shared partial, on
                                                 the screen that was telling the operator to go somewhere they
                                                 cannot. --}}
                                            @include('livewire.counter.partials.membership-fix')
                                            @include('livewire.counter.partials.inline-fee')
                                        @endunless
                                    </div>
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
                    // lands somewhere visible rather than into a section that does not exist yet. Which source is
                    // browsed is the browser's since prompt 293 (the tab makes no request), so that case is an
                    // `x-show` on the shared Alpine store rather than a server branch.
                    $showBarSection = $barEnabled && ($hasBarLines || $hasDispensationLines);
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
                                {{-- Prompt 358 — tap the line to change its amount (the pad opens with it, «Actualizar»); × removes. --}}
                                <button type="button" wire:click="editLine({{ $line['index'] }})" data-edit-line="{{ $line['index'] }}"
                                        aria-label="{{ __('Cambiar la cantidad de :name', ['name' => $line['genetic_name']]) }}"
                                        @class(['-mx-1 min-h-11 min-w-0 flex-1 rounded-lg px-1 text-left transition hover:bg-black/5 dark:hover:bg-white/5', 'ring-2 ring-brand' => $editingLine === $line['index']])>
                                    <p class="truncate font-medium">
                                        {{ $line['genetic_name'] }}
                                        @if (($mergeNote['index'] ?? null) === $line['index'])
                                            {{-- …and a merge says so, so a line that grew is not mistaken for one that vanished. --}}
                                            <span data-merge-note class="ml-1 rounded-full bg-success/10 px-1.5 py-0.5 text-[11px] font-semibold text-success">{{ $mergeNote['note'] }}</span>
                                        @endif
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
                                    {{-- Prompt 278 — the line crosses into a differently priced lote: say so BEFORE commit. --}}
                                    @if ($line['split_note'] ?? null)
                                        <p data-split-note class="text-xs font-medium text-warning">{{ $line['split_note'] }}</p>
                                    @endif
                                </button>
                                <div class="flex shrink-0 items-center gap-2">
                                    <span class="font-semibold tabular-nums">{{ $this->money($line['total_cents']) }}</span>
                                    <button type="button" wire:click="removeLine({{ $line['index'] }})" aria-label="{{ __('Quitar de la cesta') }}" class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-ink-muted hover:bg-black/5 dark:text-slate-400 dark:hover:bg-white/5">✕</button>
                                </div>
                            </li>
                        @empty
                            {{-- Prompt 272 — ONE empty state. With both baskets empty the hint below speaks, so this line
                                 only says something while the bar basket holds lines and the flower basket does not. --}}
                            @if ($hasBarLines)
                                <li class="py-6 text-center text-sm text-ink-muted dark:text-slate-400">{{ __('Cesta vacía. Elige una genética e introduce el peso.') }}</li>
                            @endif
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
                    {{-- Prompt 263 — ONE figure for the visit: this header, the tender's "a cobrar" and the pay button
                         all show the same total. With bar lines it is the visit's total (aportación + barra, two
                         ledgers, one payment); with flower only it is still labelled the aportación it is. --}}
                    @if ($roundingCents !== 0)
                        {{-- Prompt 350 — the discounted aportación rounded to the euro: said, not hidden in the total. --}}
                        <div data-basket-rounding class="mt-2 flex items-center justify-between px-4 text-sm text-ink-muted dark:text-slate-400">
                            <span>{{ __('Redondeo') }}</span>
                            <span class="tabular-nums">{{ $this->money($roundingCents) }}</span>
                        </div>
                    @endif
                    <div class="mt-3 flex items-center justify-between rounded-xl bg-surface-alt px-4 py-3 dark:bg-slate-800">
                        <span class="font-semibold">{{ $hasBarLines ? __('Total de la visita') : __('Total aportación') }}</span>
                        <span data-visit-total class="text-lg font-bold tabular-nums">{{ $this->money($visitTotalCents) }}</span>
                    </div>

                    {{-- Bar/merch side of the SAME visit (prompt 118): add articles, then settle the whole visit
                         once — one payment, but a dispensation AND a bar order on their separate ledgers. Only
                         where the sede runs a bar. The shared tender below covers the combined total. --}}
                    @if ($barEnabled)
                        <div data-cart-bar-section @unless ($showBarSection) x-show="$store.counterCatalogue.source === 'bar'" x-cloak @endunless class="mt-3 rounded-xl border border-line p-3 dark:border-slate-700">
                            <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted dark:text-slate-400">{{ __('Barra y tienda (misma visita)') }}</p>

                            @if (! empty($barLines))
                                <ul class="mt-2 divide-y divide-line dark:divide-slate-800">
                                    @foreach ($barLines as $line)
                                        <li class="flex items-center justify-between gap-2 py-1.5 text-sm" @if ($line['manual']) data-bar-line-manual @endif>
                                            @if ($line['manual'])
                                                {{-- Prompt 331 — "Descripción · importe", tagged: not a catalogue article. --}}
                                                <span class="min-w-0">{{ $line['name'] }} · {{ $this->money($line['line_total_cents']) }}
                                                    <span class="ml-1 rounded-full border border-line px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-ink-muted dark:border-slate-700 dark:text-slate-400">{{ __('manual') }}</span></span>
                                            @else
                                                <span>{{ $line['qty'] }}× {{ $line['name'] }}</span>
                                            @endif
                                            <span class="flex items-center gap-2 tabular-nums">
                                                @unless ($line['manual']){{ $this->money($line['line_total_cents']) }}@endunless
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
                                <p class="mt-2 text-xs text-ink-muted dark:text-slate-400" x-show="$store.counterCatalogue.source === 'bar'" x-cloak>{{ __('Toca un producto para añadirlo a esta visita.') }}</p>
                                <p class="mt-2 text-xs text-ink-muted dark:text-slate-400" x-show="$store.counterCatalogue.source !== 'bar'">{{ __('Cambia a Barra arriba para añadir productos a esta visita.') }}</p>
                            @endunless

                            @if ($hasBarLines)
                                {{-- The bar's own total, stated on its own line: a bar-only visit had no total
                                     anywhere on screen, because the only one rendered was the aportación's
                                     (prompt 224). --}}
                                <div class="mt-2 flex items-center justify-between border-t border-line pt-2 text-sm dark:border-slate-700">
                                    <span class="font-semibold">{{ __('Total barra y tienda') }}</span>
                                    <span class="font-bold tabular-nums">{{ $this->money($barTotalCents) }}</span>
                                </div>

                                {{-- Prompt 263 — no pay button of its own here any more. It sat beside the big
                                     "Registrar aportación" with a DIFFERENT total, and the big one recorded the
                                     dispensation only: drinks left unpaid. The one button at the foot pays the visit. --}}
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
                    @if ($this->userCan('dispensation.price.override'))
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

                        {{-- Prompt 245 — `x-show`, not `x-if`, inside a Livewire-morphed view (the surface's
                             family): Alpine-inserted DOM inside a morph target is owned by neither. --}}
                        <div data-price-override x-show="open" x-cloak class="mt-2 rounded-xl border border-warning/30 bg-warning/5 p-3">
                            <p class="block text-xs font-medium text-warning">{{ __('Ajustar precio (queda registrado)') }}</p>
                            {{-- Prompt 336 — STACKED, each the column's full width. `sm:grid-cols-2` keyed the split to the VIEWPORT, but this
                                 sits in the ~300 px basket column, so each field got ~120 px and «Aprobado por responsable»
                                 read "Manager apı". --}}
                            <div class="mt-1 grid gap-2">
                                <div>
                                <label for="price-override-amount" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Nuevo total (€)') }}</label>
                                <input id="price-override-amount" type="text" inputmode="decimal" wire:model.live.blur.enter="priceOverrideEuros" autocomplete="off" class="mt-1 h-11 w-full rounded-xl border border-line bg-surface px-3 text-base text-ink placeholder:text-ink-muted focus:border-warning focus:outline-none focus:ring-2 focus:ring-warning/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                                {{-- Prompt 333 — `.live.blur.enter`, not `.blur`: in Livewire 4 a modifier BEFORE `.live` only syncs
                                     in the browser, so `.blur` sent nothing and the button, the header and "Falta" kept the old
                                     total until the next tap (Ben: "it doesn't update the total at the bottom"). Now blur or Enter
                                     re-renders them; the field says when the figure is not taken. --}}
                                @if ($priceOverrideNotice)
                                    {{-- Prompt 356 — which way the new total goes: «+4.00 € sobre el precio calculado» / «−5.00 € …». --}}
                                    <p data-price-override-notice role="alert" class="mt-1 text-xs font-medium text-warning">{{ $priceOverrideNotice }}</p>
                                @endif
                                {{-- Prompt 356 — after a RAISE, the lasting fix: the batch's own price, in the panel (prices.manage only). --}}
                                @foreach ($batchPriceLinks as $link)
                                    <a href="{{ $link['url'] }}" data-batch-price-link wire:navigate.ignore
                                       @click.prevent="(! ($store.counter?.dirty) || window.confirm(@js(__('Tienes trabajo sin guardar en el mostrador. ¿Seguro que quieres salir?')))) && window.location.assign(@js($link['url']))"
                                       class="mt-1 inline-flex min-h-11 items-center text-xs font-semibold text-brand underline dark:text-slate-200">{{ __('¿El lote está mal de precio? Cambiar el precio del lote') }}@if (count($batchPriceLinks) > 1) · {{ $link['label'] }}@endif</a>
                                @endforeach
                                </div>
                                {{-- Prompt 356 — an OPTIONAL reason is not shown: a holder of `reasons.optional` (a manager, by default)
                                     gets no box, and the writer records «Aprobado por responsable». Everyone else types one. --}}
                                @unless ($reasonOptional)
                                <div>
                                <label for="price-override-reason" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Motivo') }}</label>
                                <input id="price-override-reason" type="text" wire:model.blur="priceOverrideReason" data-reason-required autocomplete="off" placeholder="{{ __('Motivo (p. ej. producto defectuoso)') }}" class="mt-1 h-11 w-full rounded-xl border border-line bg-surface px-3 text-base text-ink placeholder:text-ink-muted focus:border-warning focus:outline-none focus:ring-2 focus:ring-warning/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                                </div>
                                @endunless
                            </div>
                            <p class="mt-1 text-[11px] text-ink-muted dark:text-slate-400">{{ __('Deja el importe vacío para cobrar el precio normal. 0 € = gratis.') }}</p>
                        </div>
                        </div>
                    @endif
                    @endif

                    {{-- Tender (prompt 74): wallet APPLIED + physical cash TENDERED → change. The cash field is
                         what the member handed over, never the charge; the recorded contribution is the total.
                         Rendered for EITHER basket, and the figures are the COMBINED ones — the split the
                         settle actually applies (prompt 224). --}}
                    <div class="mt-4 space-y-3">
                        {{-- Prompt 268 — the wallet input only when the held member has something to SPEND here (a positive
                             balance). At €0 or in debt it was noise; the tab (259) sets walletInput itself and needs no box. --}}
                        @if ($member !== null && $walletCents > 0)
                        <div>
                            <label for="wallet" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Monedero (€)') }}</label>
                            <input id="wallet" type="text" inputmode="decimal" wire:model.live.debounce.400ms="walletInput" @disabled($member === null) autocomplete="off" placeholder="0.00" class="mt-1 h-11 w-full rounded-xl border border-line bg-surface px-3 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                        </div>
                        @endif

                        {{-- Quick cash --}}
                        <div>
                            <div class="flex items-center justify-between">
                                {{-- Prompt 272 — a real <label for>, as the bar's cash field has had since August: a placeholder
                                     is not a label, and it vanished on the first keystroke of the field that decides the drawer. --}}
                                <label for="pos-cash-tendered" class="text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Efectivo entregado') }}</label>
                                {{-- Prompt 268 — the notes ADD now, so a mistaken tap needs an undo. --}}
                                <button type="button" wire:click="clearTendered" data-clear-tendered aria-label="{{ __('Borrar efectivo entregado') }}" class="min-h-11 px-2 text-xs font-semibold text-ink-muted hover:text-brand dark:text-slate-400">{{ __('Borrar') }}</button>
                            </div>
                            <div class="mt-1 grid grid-cols-4 gap-2">
                                <button type="button" wire:click="quickCash" class="h-11 rounded-xl border border-line bg-surface text-sm font-semibold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:hover:bg-slate-800">{{ __('Justo') }}</button>
                                <button type="button" wire:click="quickCash(500)" aria-label="{{ __('Añadir :money', ['money' => $this->money(500)]) }}" class="h-11 rounded-xl border border-line bg-surface text-sm font-semibold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:hover:bg-slate-800">€5</button>
                                <button type="button" wire:click="quickCash(1000)" aria-label="{{ __('Añadir :money', ['money' => $this->money(1000)]) }}" class="h-11 rounded-xl border border-line bg-surface text-sm font-semibold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:hover:bg-slate-800">€10</button>
                                <button type="button" wire:click="quickCash(2000)" aria-label="{{ __('Añadir :money', ['money' => $this->money(2000)]) }}" class="h-11 rounded-xl border border-line bg-surface text-sm font-semibold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:hover:bg-slate-800">€20</button>
                            </div>
                            <input id="pos-cash-tendered" type="text" inputmode="decimal" wire:model.live.debounce.400ms="cashTendered" autocomplete="off" placeholder="0.00" class="mt-2 h-11 w-full rounded-xl border border-line bg-surface px-3 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                        </div>

                        {{-- Prompt 272 — slate-950, not 800, in dark (an inset well inside the slate-900 card): the dark success token
                             was only ever checked against the darkest surfaces, and "Cambio" (the figure counted into a hand) was 4.43:1 on 800. Polite live
                             region, so a quick-cash tap that flips Falta / Cambio is announced, not visual-only. --}}
                        <dl data-tender-summary aria-live="polite" class="space-y-1 rounded-xl bg-surface-alt px-4 py-3 text-sm dark:bg-slate-950">
                            <div class="flex items-center justify-between">
                                <dt class="text-ink-muted dark:text-slate-400">{{ __('A cobrar en efectivo') }}</dt>
                                <dd data-cash-due class="font-semibold tabular-nums">{{ $this->money($cashPreviewCents) }}</dd>
                            </div>
                            @if ($walletPreviewCents > 0)
                                <div data-tender-wallet class="flex items-center justify-between">
                                    <dt class="text-ink-muted dark:text-slate-400">{{ __('Monedero') }}</dt>
                                    <dd class="font-semibold tabular-nums">{{ $this->money($walletPreviewCents) }}</dd>
                                </div>
                            @endif
                            {{-- Prompt 268 — while less has been handed over than is owed, what is still to collect ("Falta");
                                 otherwise the change. The commit's own refusal stays the guard. --}}
                            @if ($shortfallCents > 0)
                                <div data-cash-shortfall class="flex items-center justify-between border-t border-line pt-1 dark:border-slate-700">
                                    <dt class="font-medium text-error">{{ __('Falta') }}</dt>
                                    <dd class="text-base font-bold tabular-nums text-error">{{ $this->money($shortfallCents) }}</dd>
                                </div>
                            @else
                                <div data-change-due class="flex items-center justify-between border-t border-line pt-1 dark:border-slate-700">
                                    <dt class="font-medium">{{ __('Cambio') }}</dt>
                                    <dd class="text-base font-bold tabular-nums {{ $changeDueCents > 0 ? 'text-success' : '' }}">{{ $this->money($changeDueCents) }}</dd>
                                </div>
                            @endif
                            @if ($walletPreviewCents > 0)
                                <div data-tender-wallet class="flex items-center justify-between text-xs text-ink-muted dark:text-slate-400">
                                    <dt>{{ __('Monedero tras aportación') }}</dt>
                                    <dd class="font-medium {{ $projectedWalletCents < 0 ? 'text-error' : '' }}">{{ $this->money($projectedWalletCents) }}</dd>
                                </div>
                            @endif
                        </dl>

                        {{-- Prompt 259 — "Añadir a la cuenta": present ONLY for a member the owner approved for a tab,
                             and only while they have headroom. It puts the part the cash handed over does not cover
                             on the tab; disabled (with the reason) when that would pass the approved limit. --}}
                        @if ($tab !== null && $tab['limit'] > 0 && $tab['headroom'] > 0)
                            <div data-tab class="rounded-xl border border-line px-4 py-3 text-sm dark:border-slate-700">
                                <p class="text-xs text-ink-muted dark:text-slate-400">
                                    {{ __('Cuenta aprobada hasta :limit · debe :owed · margen :headroom', [
                                        'limit' => $this->money($tab['limit']),
                                        'owed' => $this->money($tab['owed']),
                                        'headroom' => $this->money($tab['headroom']),
                                    ]) }}
                                </p>
                                <x-button type="button" variant="secondary" size="md" class="mt-2 w-full" wire:click="commitOnTab" data-add-to-tab :disabled="! $tab['fits']">
                                    {{ __('Añadir a la cuenta · :money', ['money' => $this->money($tab['remainder'])]) }}
                                </x-button>
                                @unless ($tab['fits'])
                                    <p class="mt-1 text-[11px] text-error">{{ $tab['remainder'] > 0 ? __('Supera el límite de deuda aprobado.') : __('No queda nada por cobrar.') }}</p>
                                @endunless
                            </div>
                        @endif
                    </div>

                    {{-- Signature (only when the sede requires it). Prompt 220 extracted the pad to
                         `x-counter.signature-pad` — same markup, same Alpine behaviour, same vault path; it is
                         a component now because it has a second consumer (the application form).

                         With the dispensation basket, not the bar's: it is the signature for the aportación,
                         and the one commit (`attemptCommit`) asks for it only when a dispensation is being written. --}}
                    @if ($requireSignature && $hasDispensationLines)
                        <div class="mt-4 border-t border-line pt-4 dark:border-slate-800">
                            <x-counter.signature-pad
                                capture="saveSignature"
                                draft="signatureDraft"
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
                                <label for="override-reason" class="mt-2 block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Motivo de la excepción (queda registrado)') }}</label>
                                <textarea id="override-reason" wire:model="overrideReason" rows="2" class="mt-1 w-full rounded-xl border border-warning/40 bg-surface px-3 py-2 text-sm focus:border-warning focus:outline-none focus:ring-2 focus:ring-warning/40 dark:bg-slate-950"></textarea>
                                <x-button variant="warning" size="md" wire:click="commitWithOverride" wire:loading.attr="disabled" wire:target="commitWithOverride" x-bind:disabled="! online" class="mt-2 w-full">{{ __('Autorizar y registrar') }}</x-button>
                            @else
                                @include('livewire.counter.partials.authorise-with-pin', ['action' => 'commitWithAuthoriserPin', 'reasonModel' => 'overrideReason'])
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
                        {{-- The next step for THIS state (prompt 272): it said "identify a socio" while one was held. --}}
                        <p data-empty-basket-hint class="rounded-xl border border-dashed border-line px-4 py-6 text-center text-sm text-ink-muted dark:border-slate-700 dark:text-slate-400">
                            {{ $member ? __('Cesta vacía. Elige una genética e introduce el peso.') : __('Identifica a un socio y añade una genética para empezar.') }}
                        </p>
                    @endif
                </section>

                {{-- Just committed → one line, the receipt / email / void behind *Opciones* (prompt 300). --}}
                @if ($lastSale)
                    @include('livewire.counter.partials.last-sale', [
                        'summary' => $lastSale['summary'],
                        'receiptUrl' => route('counter.pos.receipt', $lastDispensationId),
                        'receiptLabel' => __('Ver / imprimir recibo'),
                        'receiptHeading' => __('Recibo'),
                        'emailable' => $lastSale['emailable'],
                        'canVoid' => $canVoid,
                        'voidHeading' => __('Anular la dispensación'),
                        'voidReasonId' => 'pos-void-reason',
                    ])
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
                        class="mt-4 h-16 w-full rounded-xl bg-brand text-lg font-bold text-white transition hover:bg-brand-dark focus:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2 focus-visible:ring-offset-surface dark:focus-visible:ring-offset-slate-950 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{-- THE TOTAL ON THE BUTTON (prompt 225). The figure and the act are one thing at the
                             moment of pressing it, and the operator reads the total last — from the button
                             they are already looking at, not from a panel above it that may have scrolled. --}}
                        <span wire:loading.remove wire:target="commitDispensation">
                            {{-- Three states, one button (prompt 263): the aportación alone; or the whole visit
                                 (aportación + barra, or barra only), settled together. --}}
                            {{ $hasBarLines
                                ? __('Cobrar visita · :total', ['total' => $this->money($visitTotalCents)])
                                : ($basketTotalCents > 0
                                    ? __('Registrar aportación · :total', ['total' => $this->money($visitTotalCents)])
                                    : __('Registrar aportación')) }}
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
    const sync = () => { if (window.Alpine?.store('counter')) window.Alpine.store('counter').dirty = ((($wire.basket?.length ?? 0) + ($wire.barBasket?.length ?? 0)) > 0); };
    $wire.$watch('basket', sync);
    $wire.$watch('barBasket', sync); // prompt 263 — unpaid drinks are unsaved work too
    sync();
</script>
@endscript
