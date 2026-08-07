{{-- The counter's front door (prompt 189). A chooser, not a working surface — so it is the one counter
     screen that can afford to be generous with space. --}}
<div>
    @include('livewire.counter.partials.counter-surface')

    @if (! $this->handoverActive())

    {{-- Prompt 175 — the same chain, resolved to one. The home screen has no till or member step (choosing
         where to go IS the work), so those are absent from the chain rather than false. It is a front door,
         not a way around a precondition: no sede blocks it exactly as it blocks the others. --}}
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
            {{-- The SAME copy the other four screens use, and deliberately so: it points at the top bar,
                 which is where the switcher still lives. Home's own sede buttons sit past this blocker, so
                 they cannot be the answer to it — that would be prompt 187's deadlock all over again. --}}
            :body="$mustChooseLocation ? __('Trabajas en varias sedes. Selecciona en la barra superior en cuál estás.') : __('No tienes ninguna sede activa. Pide a un responsable que te asigne una.')"
        />
    @else
        <div class="mx-auto flex w-full max-w-6xl flex-col gap-8">
            {{-- THE TILES. One per destination the operator may open, from App\Support\CounterScreens — the
                 same list, with the same gates, that the tab strip reads. Never a second copy (prompt 172).
                 auto-fit so one permission gives one tile and five give five, at either orientation. --}}
            <div data-counter-home-tiles class="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(11rem,1fr))]">
                @foreach ($this->tiles() as $tile)
                    <a
                        href="{{ route($tile['route']) }}"
                        data-counter-home-tile="{{ $tile['route'] }}"
                        wire:navigate.ignore
                        class="flex min-h-[8rem] flex-col items-center justify-center gap-3 rounded-2xl border border-line bg-surface p-6 text-center shadow-sm transition hover:border-brand hover:bg-brand-tint hover:text-brand focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand dark:border-slate-800 dark:bg-slate-900 dark:hover:border-brand dark:hover:bg-slate-800"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="h-10 w-10" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $tile['icon'] }}"/>
                        </svg>
                        <span class="text-base font-semibold">{{ $tile['label'] }}</span>
                    </a>
                @endforeach
            </div>

            {{-- THE TERMINAL. Per the Dynamics split the branch follows: operations that are not specific to
                 the current transaction belong on the welcome screen, not on every working surface. Moving
                 these four off the bar is what buys the room the owner asked for. --}}
            <section data-counter-home-terminal class="rounded-2xl border border-line bg-surface-alt p-5 dark:border-slate-800 dark:bg-slate-900/50">
                <h2 class="text-sm font-semibold text-ink-muted dark:text-slate-400">{{ __('Terminal') }}</h2>

                <div class="mt-4 flex flex-wrap items-center gap-3">
                    {{-- WHO IS WORKING — switching happens through 173's surface, the one route to the pad. --}}
                    @if ($this->hasOperator())
                        <button
                            type="button"
                            wire:click="switchOperator"
                            data-counter-home-switch-operator
                            class="inline-flex min-h-[2.75rem] items-center gap-2 rounded-xl border border-line bg-surface px-4 text-sm font-medium transition hover:border-brand hover:text-brand dark:border-slate-700 dark:bg-slate-900 dark:hover:border-brand"
                        >
                            <span class="inline-block h-2.5 w-2.5 rounded-full bg-success" aria-hidden="true"></span>
                            <span class="text-ink-muted dark:text-slate-400">{{ __('Trabajando') }}:</span>
                            <span class="font-semibold">{{ $this->currentOperatorName() }}</span>
                            <span class="text-xs text-ink-muted dark:text-slate-500">· {{ __('Cambiar') }}</span>
                        </button>
                    @endif

                    {{-- LOCK — still one tap from here; the idle timer is unchanged. --}}
                    <button
                        type="button"
                        @click="$store.counter.lockNow()"
                        data-counter-home-lock
                        class="inline-flex min-h-[2.75rem] items-center gap-2 rounded-xl border border-line bg-surface px-4 text-sm font-medium transition hover:border-brand hover:text-brand dark:border-slate-700 dark:bg-slate-900 dark:hover:border-brand"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 0h10.5a2.25 2.25 0 0 1 2.25 2.25v6.75a2.25 2.25 0 0 1-2.25 2.25H6.75a2.25 2.25 0 0 1-2.25-2.25v-6.75a2.25 2.25 0 0 1 2.25-2.25Z"/>
                        </svg>
                        {{ __('Bloquear pantalla') }}
                    </button>

                    @if ($this->canReachPanel())
                        <a
                            href="{{ url('/') }}"
                            data-counter-home-panel
                            wire:navigate.ignore
                            class="inline-flex min-h-[2.75rem] items-center gap-2 rounded-xl border border-line bg-surface px-4 text-sm font-medium transition hover:border-brand hover:text-brand dark:border-slate-700 dark:bg-slate-900 dark:hover:border-brand"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12 12 2.25 21.75 12M4.5 9.75v9.75a.75.75 0 0 0 .75.75H9.75V15.75a1.5 1.5 0 0 1 1.5-1.5h1.5a1.5 1.5 0 0 1 1.5 1.5v4.5h4.5a.75.75 0 0 0 .75-.75V9.75"/>
                            </svg>
                            {{ __('Panel') }}
                        </a>
                    @endif

                    <form method="POST" action="{{ route('filament.admin.auth.logout') }}">
                        @csrf
                        <button
                            type="submit"
                            data-counter-home-logout
                            class="inline-flex min-h-[2.75rem] items-center gap-2 rounded-xl border border-line bg-surface px-4 text-sm font-medium text-ink-muted transition hover:border-error hover:text-error dark:border-slate-700 dark:bg-slate-900"
                        >
                            {{ __('Cerrar sesión') }}
                        </button>
                    </form>
                </div>

                {{-- SWITCH SEDE. The only writer is still the validated POST /counter/location route; this
                     screen offers exactly the sedes LocationSwitcher says the operator may work at. --}}
                @if ($this->availableSedes()->count() > 1)
                    <div class="mt-5 border-t border-line pt-4 dark:border-slate-800">
                        <p class="text-xs text-ink-muted dark:text-slate-400">{{ __('Selecciona la sede en la que trabajas.') }}</p>
                        <div data-counter-home-sedes class="mt-3 flex flex-wrap gap-2">
                            @foreach ($this->availableSedes() as $sede)
                                @php $isCurrent = $locationId === $sede->id; @endphp
                                <form method="POST" action="{{ route('counter.location') }}">
                                    @csrf
                                    <input type="hidden" name="location_id" value="{{ $sede->id }}">
                                    <button
                                        type="submit"
                                        data-counter-home-sede="{{ $sede->id }}"
                                        @class([
                                            'inline-flex min-h-[2.75rem] items-center gap-2 rounded-xl border px-4 text-sm font-medium transition',
                                            'border-brand bg-brand-tint text-brand dark:bg-slate-800 dark:text-white' => $isCurrent,
                                            'border-line bg-surface hover:border-brand hover:text-brand dark:border-slate-700 dark:bg-slate-900' => ! $isCurrent,
                                        ])
                                        @if ($isCurrent) aria-current="true" @endif
                                    >
                                        {{ $sede->name }}
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    </div>
                @endif
            </section>
        </div>
    @endif

    @endif
</div>
