{{-- The counter's chrome (prompt 209). Rendered by the layout, but decided HERE, because a Livewire response
     re-renders a component and never the Blade layout around it.

     Skip link (a11y audit): the top bar is ~7 controls to tab past on EVERY counter screen, and the panel
     already has one. Visually hidden until focused, then it is the first thing on the page. Inside the
     handover guard, same rule as the rest of the chrome.

     One shared header for every counter terminal (the club + this screen's title, the sede, who is working,
     Lock, and — behind a divider, because they LEAVE the counter — a permission-filtered Administración link
     + Log out). See x-counter.top-bar.

     Handed over (prompt 173): the chrome is ABSENT from the DOM, not hidden by CSS. The Administración link,
     Log out, the sede switcher and the panic button are all inside the bar — while an applicant holds the
     tablet there is no element to find, no link to follow and nothing for a keyboard to reach. --}}
<div>
    {{-- Prompt 324 — *Modo formación*. The banner is OUTSIDE the handover guard: while training is on it is on every
         screen, fixed at the top, and nothing dismisses it but *Salir del modo formación*. --}}
    @if ($training)
        <div data-training-banner role="status"
             class="sticky top-0 z-[55] flex flex-wrap items-center justify-center gap-x-4 gap-y-1 bg-warning px-4 py-2 text-center text-ink"
             {{-- Edge to edge: out of the layout's side padding (the 100% here is the padded column; the rest reaches the viewport's edges). --}}
             style="background-image: repeating-linear-gradient(135deg, rgb(15 23 42 / .14) 0 14px, transparent 14px 28px); margin-inline: calc(50% - 50dvw);">
            <strong class="text-base font-extrabold tracking-wide">{{ __('MODO FORMACIÓN — nada de esto cuenta') }}</strong>
            <form method="POST" action="{{ route('counter.training.leave') }}">
                @csrf
                <button type="submit" data-training-leave
                        class="inline-flex min-h-11 items-center rounded-lg border-2 border-ink bg-surface px-4 text-sm font-bold text-ink hover:bg-surface-alt">{{ __('Salir del modo formación') }}</button>
            </form>
        </div>
        <style>
            {!! \App\Support\TrainingMode::writeButtonSelectors() !!} {
                content: {!! json_encode(' '.__('(práctica)'), JSON_UNESCAPED_UNICODE) !!}; font-weight: 800;
            }
        </style>
    @endif
    @if (session('counter_training_refused'))
        <p role="alert" data-training-refused class="mx-4 mt-2 rounded-xl border border-warning/30 bg-warning/10 px-4 py-2 text-sm font-medium text-warning">{{ session('counter_training_refused') }}</p>
    @endif
    @unless ($handedOver)
        <a href="#counter-main"
           class="sr-only rounded-xl bg-brand text-sm font-semibold text-white focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-[60] focus:px-4 focus:py-2">{{ __('Saltar al contenido') }}</a>
        <x-counter.top-bar :title="$title" />
        {{-- Prompt 338 — the till's opening and the hours record: clocked in (with Deshacer for 2 minutes), or asked. In the
             chrome, because opening the till moves on to the next screen. --}}
        @if ($clockInNotice !== null)
            <div role="status" data-clock-in-notice class="mx-4 mt-3 flex flex-wrap items-center gap-3 rounded-xl border border-success/40 bg-success/10 px-4 py-2 text-sm text-ink dark:text-slate-100 sm:mx-6">
                <span class="flex-1">{{ __('Entrada fichada a las :time.', ['time' => $clockInNotice]) }}</span>
                <x-button size="sm" variant="secondary" wire:click="undoClockIn" data-clock-in-undo>{{ __('Deshacer') }}</x-button>
            </div>
        @elseif ($clockInOffer)
            <div role="status" data-clock-in-offer class="mx-4 mt-3 flex flex-wrap items-center gap-3 rounded-xl border border-warning/50 bg-warning/10 px-4 py-2 text-sm text-ink dark:text-slate-100 sm:mx-6">
                <span class="flex-1 font-semibold">{{ __('¿Fichar entrada ahora?') }}</span>
                <x-button size="sm" variant="secondary" wire:click="declineClockIn" data-clock-in-no>{{ __('No') }}</x-button>
                <x-button size="sm" wire:click="acceptClockIn" data-clock-in-yes>{{ __('Sí') }}</x-button>
            </div>
        @endif
        @if ($clockMessage)
            <p role="alert" class="mx-4 mt-2 rounded-lg bg-warning/10 px-3 py-2 text-sm font-medium text-warning sm:mx-6">{{ $clockMessage }}</p>
        @endif
        {{-- Prompt 324 — entering asks first; the sheet lives here (opened by the top bar's button by name). --}}
        @if (! $training && $trainingAllowed)
            <x-counter.sheet name="training" :heading="__('Modo formación')">
                <p class="text-sm text-ink dark:text-slate-100">{{ __('Nada de lo que hagas se guardará. Úsalo solo para practicar.') }}</p>
                <p class="mt-2 text-xs text-ink-muted dark:text-slate-400">{{ __('Precios, límites y existencias son los reales. Cada paso se descarta al instante, así que practica sobre la caja abierta de verdad.') }}</p>
                <form method="POST" action="{{ route('counter.training.start') }}" class="mt-4 flex justify-end gap-2">
                    @csrf
                    <x-button type="button" variant="secondary" x-on:click="close()">{{ __('Cancelar') }}</x-button>
                    <x-button type="submit" data-training-start>{{ __('Entrar en modo formación') }}</x-button>
                </form>
            </x-counter.sheet>
        @endif
    @endunless
</div>
