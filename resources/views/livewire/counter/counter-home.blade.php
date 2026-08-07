{{-- The counter HUB (prompts 189 + 205) — the menu, and a screen worth landing on.

     189 made this a chooser. The owner then reported two things about it: *"you go to it and can't get back
     to it"* and *"it's just duplicate data"*, and both checked out. The route home was an unlabelled logo
     tile; and after 189 the destinations, the sede, the working operator, Panel and Log out were all in the
     top bar AND here. 205 settles the model the owner chose: **the hub is the menu, the bar is the terminal
     strip**, and every control exists in exactly one place.

     The trade he accepted with it: switching screens is two taps instead of one, in exchange for one place
     per control. That trade is only payable because the basket now survives navigation (App\Support\CounterBasket).

     Every figure comes from App\ViewModels\Dashboard. 177's rule on a new screen: if a number here ever
     disagrees with the panel or the dispensary, THIS SCREEN is wrong and the resolver is right.

     No polling. The hub is now the most-hit page in the product — it renders on every navigation, all shift —
     so a refresh timer would burn the query budget on nothing AND be exactly the thing that quietly breaks
     prompt 120's rule that only genuine pointer/key/touch input resets the idle lock. It is fresh because it
     is re-rendered by real navigation, which is the only kind that matters. --}}
<div>
    @include('livewire.counter.partials.counter-surface')

    @if (! $this->handoverActive())

    {{-- Prompt 175 — the same chain, resolved to one. The hub has no till or member step (choosing where to
         go IS the work). It is a front door, not a way around a precondition: no sede blocks it exactly as it
         blocks the others. --}}
    @php
        $blocker = \App\Support\CounterBlocker::first([
            \App\Support\CounterBlocker::SEDE => ! $noLocation,
            \App\Support\CounterBlocker::OPERATOR => $this->hasOperator(),
        ]);
    @endphp

    @if (\App\Support\CounterBlocker::rendersInPage($blocker))
        <x-counter.blocking-state
            data-blocker="sede"
            icon="📍"
            :heading="$mustChooseLocation ? __('Elige tu sede') : __('Sin sede asignada')"
            :body="$mustChooseLocation ? __('Trabajas en varias sedes. Selecciona en la barra superior en cuál estás.') : __('No tienes ninguna sede activa. Pide a un responsable que te asigne una.')"
        />
    @else
        @php($panels = $this->panels())
        @php($hero = $this->heroTile())

        <div class="mx-auto grid w-full max-w-6xl gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
            {{-- ============ THE TILES ============
                 Asymmetric: one hero, the rest secondary. The hero is the FIRST destination this operator may
                 open, which was Recepción only because Recepción is first in `CounterScreens` — an array
                 ordered for THIS grid's reading order and for `landingRouteFor()`'s fallback, which 205 then
                 quietly made carry a third job it was never written for.

                 **Prompt 208 made the hero its own decision**: the `counter_hero` Setting, defaulting to the
                 dispensary, which is the owner's call — with 205's two-tap navigation the hero saves a tap on
                 whatever it names, every time, and dispensing is what a shift spends its time on. The
                 per-role property that made deriving it attractive is kept rather than lost: an operator who
                 cannot open the configured hero falls back to the first destination they can, so a till-only
                 operator's hero is still Caja and nobody ever gets a hole where their hero should be. --}}
            <div data-counter-home-tiles class="flex flex-col gap-4">
                @if ($hero)
                    <a
                        href="{{ route($hero['route']) }}"
                        data-counter-home-tile="{{ $hero['route'] }}"
                        data-counter-home-hero
                        wire:navigate.ignore
                        class="group flex min-h-[11rem] flex-col justify-between rounded-2xl border border-brand bg-brand p-6 text-white shadow-sm transition hover:bg-brand-dark focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="h-12 w-12" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $hero['icon'] }}"/>
                        </svg>
                        <span>
                            <span class="block text-2xl font-bold">{{ $hero['label'] }}</span>
                            {{-- Full white, no tint. MEASURED rather than assumed, which is what the branch was
                                 told to do: white on --brand #2563eb is **5.17:1**, and at 80% opacity it
                                 drops to **3.89:1** — under AA for 14px text. The first draft used `/80` and
                                 carried a comment asserting 6.3:1, which was simply wrong. --}}
                            <span class="mt-1 block text-sm text-white">{{ \App\Support\CounterScreens::purposeFor($hero['route']) }}</span>
                        </span>
                    </a>
                @endif

                <div class="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(11rem,1fr))]">
                    @foreach ($this->secondaryTiles() as $tile)
                        <a
                            href="{{ route($tile['route']) }}"
                            data-counter-home-tile="{{ $tile['route'] }}"
                            wire:navigate.ignore
                            class="flex min-h-[8rem] flex-col justify-between rounded-2xl border border-line bg-surface p-5 shadow-sm transition hover:border-brand hover:bg-brand-tint hover:text-brand focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand dark:border-slate-800 dark:bg-slate-900 dark:hover:border-brand dark:hover:bg-slate-800"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="h-8 w-8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $tile['icon'] }}"/>
                            </svg>
                            <span>
                                <span class="block text-base font-semibold">{{ $tile['label'] }}</span>
                                <span class="mt-0.5 block text-xs text-ink-muted dark:text-slate-400">{{ \App\Support\CounterScreens::purposeFor($tile['route']) }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>

            {{-- ============ THE RAIL ============ Live, and every figure from Dashboard. --}}
            <aside data-counter-home-rail class="flex flex-col gap-4">
                <section data-panel="presence" class="rounded-2xl border border-line bg-surface p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-xs font-semibold uppercase tracking-wide text-ink-muted dark:text-slate-400">{{ __('En el local') }}</h2>
                    {{-- "4 esperando" from the mockup does NOT exist: check-in records who is INSIDE, not who
                         is waiting, and there is no queue concept anywhere in this application. Rather than
                         invent a number to fill a shape, the panel states the two things that are real. --}}
                    <p data-figure="inside" class="mt-1 text-4xl font-bold tabular-nums">{{ $panels['inside'] }}</p>
                    <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">{{ __('Socios dentro ahora') }}</p>
                </section>

                <section data-panel="today" class="rounded-2xl border border-line bg-surface p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-xs font-semibold uppercase tracking-wide text-ink-muted dark:text-slate-400">{{ __('Hoy') }}</h2>
                    <dl class="mt-2 space-y-1.5 text-sm">
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-ink-muted dark:text-slate-400">{{ __('Entradas') }}</dt>
                            <dd data-figure="check_ins" class="font-semibold tabular-nums">{{ $panels['check_ins'] }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-ink-muted dark:text-slate-400">{{ __('Operaciones') }}</dt>
                            <dd data-figure="transactions" class="font-semibold tabular-nums">{{ $panels['transactions'] }}</dd>
                        </div>
                        {{-- MONEY IS GATED, and this is a security judgement rather than a layout one. This
                             screen is the landing page AND the only route between screens, so it is on
                             display all shift in a room with members and visitors in it, in a cash business
                             that has a panic button because robbery is a real risk (prompt 121). The idle
                             lock covers the abandoned tablet; it does nothing about the person standing at
                             the counter, and a tap-to-reveal reveals at exactly the wrong moment. So the
                             day's takings follow Dashboard's EXISTING finance rule (reports.view), which
                             STAFF do not hold — and the panel is ABSENT for them, not blurred and not zeroed. --}}
                        @if ($panels['taken'] !== null)
                            <div data-figure-row="taken" class="flex items-baseline justify-between gap-3 border-t border-line pt-1.5 dark:border-slate-800">
                                <dt class="text-ink-muted dark:text-slate-400">{{ __('Aportaciones') }}</dt>
                                <dd data-figure="taken" class="font-semibold tabular-nums">{{ $panels['taken'] }}</dd>
                            </div>
                        @endif
                    </dl>
                </section>

                {{-- NEEDS ATTENTION — exactly what Dashboard::alerts() returns, not a list chosen here, and
                     each item leads somewhere. An alert you cannot act on is decoration. --}}
                <section data-panel="alerts" class="rounded-2xl border border-line bg-surface p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-xs font-semibold uppercase tracking-wide text-ink-muted dark:text-slate-400">{{ __('Requiere atención') }}</h2>
                    @forelse ($panels['alerts'] as $alert)
                        @php($href = $this->alertHref($alert['key']))
                        @php($dot = match ($alert['severity']) { 'error' => 'bg-error', 'warning' => 'bg-warning', default => 'bg-brand' })
                        <{{ $href ? 'a' : 'p' }}
                            @if ($href) href="{{ $href }}" wire:navigate.ignore @endif
                            data-alert="{{ $alert['key'] }}"
                            @class([
                                'mt-2 flex items-start gap-2 rounded-lg px-2 py-1.5 text-sm',
                                'transition hover:bg-surface-alt dark:hover:bg-slate-800' => (bool) $href,
                            ])
                        >
                            <span class="mt-1.5 inline-block h-2 w-2 shrink-0 rounded-full {{ $dot }}" aria-hidden="true"></span>
                            <span>{{ $this->alertLabel($alert['key'], $alert['count']) }}</span>
                        </{{ $href ? 'a' : 'p' }}>
                    @empty
                        {{-- An intentional empty state: "nothing needs you" is information, not a blank box. --}}
                        <p data-alerts-empty class="mt-2 text-sm text-ink-muted dark:text-slate-400">{{ __('Nada pendiente. Todo en orden.') }}</p>
                    @endforelse
                </section>

                @if ($panels['on_shift'] !== [])
                    <section data-panel="shift" class="rounded-2xl border border-line bg-surface p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h2 class="text-xs font-semibold uppercase tracking-wide text-ink-muted dark:text-slate-400">{{ __('En turno hoy') }}</h2>
                        <p data-figure="on_shift" class="mt-2 text-sm font-medium">{{ implode(' · ', $panels['on_shift']) }}</p>
                    </section>
                @endif

                {{-- AYUDA (prompt 92) — the same answers, moved here from the top bar's overflow. It is
                     reference content rather than a terminal operation, and the hub is where somebody has a
                     moment to read it. Static; nothing loads. Rules are NAMED, never a hard-coded value. --}}
                <details data-counter-help class="rounded-2xl border border-line bg-surface p-5 dark:border-slate-800 dark:bg-slate-900">
                    <summary class="flex min-h-11 cursor-pointer items-center text-sm font-semibold">{{ __('¿Por qué no puedo dispensar a un socio?') }}</summary>
                    <div data-counter-help-content class="mt-2">
                        <ul class="space-y-1 text-sm text-ink-muted dark:text-slate-400">
                            <li>· {{ __('No tiene una membresía activa, o no está al corriente de la cuota.') }}</li>
                            <li>· {{ __('Está en carencia: el período de espera desde el alta aún no ha terminado.') }}</li>
                            <li>· {{ __('Alcanzaría el límite diario o mensual de gramos configurado.') }}</li>
                            <li>· {{ __('No cumple la edad mínima, o su documento ha caducado.') }}</li>
                            <li>· {{ __('Debe dinero por encima del umbral permitido en el monedero.') }}</li>
                        </ul>
                        <p class="mt-2 text-xs text-ink-muted dark:text-slate-400">{{ __('El mostrador te dice el motivo exacto en cada caso. Un responsable puede autorizar algunas excepciones.') }}</p>

                        <h3 class="mt-3 text-sm font-semibold">{{ __('Términos') }}</h3>
                        <dl class="mt-1 space-y-1 text-xs">
                            @foreach (['Aportación', 'Dispensación', 'Carencia', 'Arqueo'] as $term)
                                <div>
                                    <dt class="inline font-semibold text-ink dark:text-slate-200">{{ $term }}:</dt>
                                    <dd class="inline text-ink-muted dark:text-slate-400">{{ __(\App\Support\Help::GLOSSARY[$term]) }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                </details>
            </aside>
        </div>
    @endif

    @endif
</div>
