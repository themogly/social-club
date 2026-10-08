<div class="mx-auto flex w-full max-w-2xl flex-col gap-5 lg:max-w-5xl">
    @include('livewire.counter.partials.counter-surface')

    @if (! $this->handoverActive())

    {{-- Prompt 175 — the same chain, resolved to one. The Caja screen's only counter precondition is a sede:
         opening the till IS the work here, so the till step cannot block it. The operator step is reported so
         the ordering stays the chain's, and rendered by 173's surface. Prompt 182 redesigns this screen. --}}
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
            :body="$mustChooseLocation ? __('Trabajas en varias sedes. Selecciona en la barra superior en cuál estás.') : __('No tienes ninguna sede activa. Pide a un responsable que te asigne una para gestionar la caja.')"
        />
    @else
        {{-- The shared slot at the top now answers only whole-screen outcomes (the arqueo revealed, the recount
             committed, the drawer opened or already closed) and anything raised outside a form. A form's own
             result renders INSIDE its card, beside its button (prompt 279) — at iPad landscape the operator has
             scrolled down to the form, and an answer up here landed 300–600px above the viewport. --}}
        {{-- Prompt 324 — in *Modo formación* each step is discarded at once: the till is never really opened or closed. --}}
        @if (\App\Support\TrainingMode::active())
            <p data-training-till-note class="mb-4 rounded-xl border border-warning/30 bg-warning/10 px-4 py-3 text-sm font-medium text-warning">
                {{ __('En modo formación la caja no se abre ni se cierra de verdad: cada paso se descarta. Para practicar ventas, usa la caja real ya abierta.') }}
            </p>
        @endif
        @if ($flashSlot === null)
            @include('livewire.counter.partials.counter-flash', ['anchor' => 'data-commit-feedback', 'spacing' => '', 'nonce' => $flashSeq])
        @endif

        @if ($countSubmitted && $clockedOutAt !== null)
            {{-- Prompt 312 — the closer was clocked out automatically (TILL_CLOSE, their own PIN closed the till). Two minutes
                 to take it back; after that — or once the counter is locked or someone else signs in — the server refuses. --}}
            <section data-clock-out-done class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-success/30 bg-success/10 p-4 dark:border-slate-700 dark:bg-slate-900"
                     x-data="{ left: {{ \App\Actions\Staff\UndoTillClockEvent::WINDOW_SECONDS }} }" x-init="const t = setInterval(() => { if (--left <= 0) clearInterval(t) }, 1000)">
                <p class="text-sm font-semibold">{{ __('Salida fichada a las :time.', ['time' => $clockedOutAt]) }}</p>
                <x-button type="button" variant="secondary" wire:click="undoClockOut" data-clock-out-undo x-show="left > 0" class="min-h-[2.75rem]">{{ __('Deshacer') }}</x-button>
            </section>
        @endif

        @if ($countSubmitted)
            @php $others = $this->stillClockedIn(); @endphp
            @if ($others !== [])
                {{-- Prompt 312 — everyone else still clocked in HERE is shown, never clocked out for them: each uses their own
                     PIN (the registro de jornada is personal). Names and clock-in times only. --}}
                <section data-still-clocked-in class="mb-4 rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-sm font-semibold">{{ __('Aún con jornada abierta') }}</p>
                    <ul class="mt-2 divide-y divide-line dark:divide-slate-800">
                        @foreach ($others as $other)
                            <li wire:key="still-{{ $other['id'] }}" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                <span>{{ __(':name (desde :since)', ['name' => $other['name'], 'since' => $other['since']]) }}</span>
                                @if ($clockOutOtherId === $other['id'])
                                    <span class="flex flex-wrap items-center gap-2">
                                        <label for="other-pin" class="sr-only">{{ __('PIN de :name', ['name' => $other['name']]) }}</label>
                                        <input id="other-pin" type="password" inputmode="numeric" autocomplete="off" maxlength="8" wire:model="otherPin"
                                               placeholder="{{ __('Su PIN') }}" data-other-pin
                                               class="min-h-[2.75rem] w-28 rounded-lg border border-line bg-surface px-3 tracking-[0.4em] dark:border-slate-700 dark:bg-slate-950">
                                        <x-button type="button" wire:click="confirmClockOutFor" data-other-pin-confirm class="min-h-[2.75rem]">{{ __('Fichar salida') }}</x-button>
                                        <x-button type="button" variant="secondary" wire:click="cancelClockOutFor" class="min-h-[2.75rem]">{{ __('Cancelar') }}</x-button>
                                    </span>
                                @else
                                    <x-button type="button" variant="secondary" wire:click="startClockOutFor('{{ $other['id'] }}')" data-clock-out-other="{{ $other['id'] }}" class="min-h-[2.75rem]">{{ __('Fichar salida') }}</x-button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @if ($otherFeedback !== null)
                        <p role="alert" data-other-pin-feedback class="mt-2 rounded-lg bg-error/10 px-3 py-2 text-sm font-medium text-error">{{ $otherFeedback }}</p>
                    @endif
                </section>
            @endif
        @endif

        @if ($countSubmitted && $clockOutOffer)
            {{-- Prompt 281 — the closer is still clocked in: offer the clock-out right here (no second PIN — the close was theirs). --}}
            <section data-clock-out-offer class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-brand/30 bg-brand-tint p-4 dark:border-slate-700 dark:bg-slate-900">
                <p class="text-sm font-semibold">{{ __('¿Fichar salida ahora?') }}</p>
                <div class="flex flex-wrap gap-2">
                    <x-button type="button" wire:click="clockOutAfterClose" data-clock-out-yes class="min-h-[2.75rem]">{{ __('Sí, fichar salida') }}</x-button>
                    <x-button type="button" variant="secondary" wire:click="dismissClockOutOffer" data-clock-out-no class="min-h-[2.75rem]">{{ __('No, sigo trabajando') }}</x-button>
                </div>
            </section>
        @endif

        @if ($countSubmitted)
            {{-- ============ Blind close REVEALED: the arqueo result ============ --}}
            @php $varianceOff = ($variance ?? 0) !== 0; @endphp
            <section class="rounded-2xl border border-line bg-surface p-6 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-lg font-semibold">{{ __('Arqueo de caja') }}</h2>
                    <span class="rounded-full border border-line bg-surface-alt px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide text-ink-muted dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ __('Cerrada') }}</span>
                </div>
                <p class="mt-0.5 text-sm text-ink-muted dark:text-slate-400">{{ __('Terminal') }}: <span class="font-medium text-ink dark:text-slate-100">{{ $terminal }}</span></p>

                <dl class="mt-5 space-y-3">
                    <div class="flex items-center justify-between">
                        <dt class="text-ink-muted dark:text-slate-400">{{ $potResults !== [] ? __('Dispensario contado') : __('Efectivo contado') }}</dt>
                        <dd class="text-lg font-semibold tabular-nums">{{ $this->money($counted ?? 0) }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-ink-muted dark:text-slate-400">{{ $potResults !== [] ? __('Dispensario esperado') : __('Efectivo esperado') }}</dt>
                        <dd class="text-lg font-semibold tabular-nums">{{ $this->money($expected ?? 0) }}</dd>
                    </div>
                    <div class="flex items-center justify-between border-t border-line pt-3 dark:border-slate-800">
                        <dt class="font-semibold">{{ __('Diferencia') }}</dt>
                        <dd @class([
                            'text-xl font-bold tabular-nums',
                            'text-error' => $varianceOff,
                            'text-success' => ! $varianceOff,
                        ])>{{ $this->money($variance ?? 0) }}</dd>
                    </div>
                </dl>

                {{-- Prompt 349 — the bar and fees pots: counted (with their difference) or carried forward. --}}
                @if ($potResults !== [])
                    <dl class="mt-4 space-y-2 border-t border-line pt-3 text-sm dark:border-slate-800" data-arqueo-pots>
                        @foreach ($potResults as $potKey => $result)
                            @php $potLabel = \App\Enums\CashPot::from($potKey)->label(); @endphp
                            <div class="flex items-baseline justify-between gap-3" data-arqueo-pot="{{ $potKey }}">
                                <dt class="text-ink-muted dark:text-slate-400">{{ $potLabel }}</dt>
                                <dd class="text-right tabular-nums">
                                    @if ($result['counted'] === null)
                                        {{ __('no contado · esperado :amount (pasa a la siguiente caja)', ['amount' => $this->money($result['expected'])]) }}
                                    @else
                                        {{ __('contado :counted · esperado :expected', ['counted' => $this->money($result['counted']), 'expected' => $this->money($result['expected'])]) }}
                                        · <span @class(['font-semibold', 'text-error' => $result['variance'] !== 0, 'text-success' => $result['variance'] === 0])>{{ $this->money($result['variance']) }}</span>
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                @endif

                {{-- Prompt 265 — the petty cash that left the drawer, itemised: this is where a difference gets explained. --}}
                @if ($closedPetty !== null && $closedPetty['items'] !== [])
                    <div data-arqueo-petty-cash class="mt-4 border-t border-line pt-3 text-sm dark:border-slate-800">
                        <div class="flex items-center justify-between">
                            <span class="font-medium">{{ __('Caja chica') }}</span>
                            <span class="font-semibold tabular-nums">{{ $this->money($closedPetty['total']) }}</span>
                        </div>
                        @include('livewire.counter.partials.petty-cash-items', ['items' => $closedPetty['items']])
                    </div>
                @endif

                @if ($varianceOff && filled($closeNote))
                    <div class="mt-4 rounded-xl border border-line bg-surface-alt px-4 py-3 text-sm dark:border-slate-800 dark:bg-slate-800">
                        <p class="font-medium">{{ __('Nota') }}</p>
                        <p class="mt-0.5 text-ink-muted dark:text-slate-300">{{ $closeNote }}</p>
                    </div>
                @endif

                <button
                    type="button"
                    wire:click="finishClose"
                    class="mt-6 h-14 w-full rounded-xl bg-brand text-base font-semibold text-white transition hover:bg-brand-dark focus:outline-none focus:ring-2 focus:ring-brand/40"
                >
                    {{ __('Abrir una nueva caja') }}
                </button>
            </section>
        @elseif ($session === null)
            {{-- ============ No open session: the OPEN SCREEN ============

                 Prompt 182 — one action, and the float on the same screen as it.

                 This was a card among cards, and on the till at iPad landscape the button sat at 50% of the
                 fold before prompt 173 and off the bottom once the PIN pad opened. 173 fixed the reflow and
                 175 made the closed till a proper blocking state; this makes what is BEHIND that right. It
                 is now the whole screen: the one thing to do, the one number it needs, and the button.

                 Square, Shopify, Lightspeed X-Series and SumUp all capture the opening amount on the same
                 screen or dialog as the open action — none uses a separate wizard step. SumUp is the closest
                 analogue to a Spanish club counter and is exactly the owner's description: the till is
                 locked, you enter the cash fund, you confirm. --}}
            <section
                data-till-open-screen
                class="mx-auto flex min-h-[60svh] w-full max-w-md flex-col justify-center rounded-2xl border border-line bg-surface p-6 text-center dark:border-slate-800 dark:bg-slate-900"
            >
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-surface-alt text-ink-muted dark:bg-slate-800 dark:text-slate-300" aria-hidden="true"><x-counter.icon name="cash" class="h-8 w-8" /></div>

                <h2 class="mt-5 text-xl font-semibold">{{ __('Abrir caja') }}</h2>
                <p class="mt-2 text-sm text-ink-muted dark:text-slate-400">{{ __('No hay ninguna caja abierta en este terminal.') }}</p>

                <form wire:submit="open" class="mt-6 space-y-4 text-left">
                    @if ($this->multipleTills())
                        {{-- Multi-till sede (prompt 102): pick one of this sede's CONFIGURED terminals. Terminals
                             are managed in admin now, never free-typed here — no phantom-till typos. --}}
                        <div>
                            <label for="terminal" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Terminal') }}</label>
                            <select
                                id="terminal"
                                wire:model="terminal"
                                class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                                <option value="">{{ __('Elige un terminal…') }}</option>
                                @foreach ($terminals as $t)
                                    <option value="{{ $t }}">{{ $t }}</option>
                                @endforeach
                            </select>
                        </div>
                    @else
                        {{-- Single-till sede (the default): one drawer, its terminal preset — only the float is asked. --}}
                        <p class="text-sm text-ink-muted dark:text-slate-400">{{ __('Terminal') }}: <span class="font-medium text-ink dark:text-slate-100">{{ $terminal }}</span></p>
                    @endif
                    <div>
                        <label for="float" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Fondo de caja (€)') }}</label>
                        {{-- Prompt 349 — the float is the dispensary pot's; the bar and fees pots are never asked for one. --}}
                        @if ($carried = $this->carriedPots())
                            <p data-till-carried class="mt-0.5 text-xs text-ink-muted dark:text-slate-400">{{ __('Del bote del dispensario. La barra (:bar) y las cuotas (:fees) abren con lo que tenían.', ['bar' => $this->money($carried['bar']), 'fees' => $this->money($carried['fees'])]) }}</p>
                        @endif
                        <input
                            id="float"
                            data-till-float
                            type="text"
                            inputmode="decimal"
                            wire:model="floatInput"
                            autocomplete="off"
                            placeholder="0.00"
                            class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        >
                        {{-- The first-ever open has no default and no previous session. That must not be an
                             empty required field with no explanation — so it says why it is empty and where
                             the figure comes from once someone sets one. --}}
                        @if ($this->defaultFloatCents() !== null)
                            <p data-float-default class="mt-1.5 text-xs text-ink-muted dark:text-slate-400">{{ __('Fondo habitual de la sede. Puedes cambiarlo.') }}</p>
                        @else
                            <p data-float-no-default class="mt-1.5 text-xs text-ink-muted dark:text-slate-400">{{ __('Esta sede no tiene fondo por defecto. Escribe el importe con el que abres; un responsable puede fijarlo en Ajustes.') }}</p>
                        @endif
                    </div>
                    <button
                        type="submit"
                        data-till-open-action
                        wire:loading.attr="disabled"
                        class="h-14 w-full rounded-xl bg-brand text-base font-semibold text-white transition hover:bg-brand-dark focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:opacity-60"
                    >
                        {{ __('Abrir caja') }}
                    </button>
                </form>
            </section>
        @elseif ($reweighing)
            {{-- ============ EOD flower reweigh (prompt 47): blind count of touched flower, before the cash arqueo ============ --}}
            @php $reweighProgress = $this->reweighProgress(); @endphp
            <section class="rounded-2xl border border-warning/40 bg-warning/5 p-5 dark:border-warning/30 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <h2 class="text-lg font-semibold">{{ __('Recuento de flor · fin de día') }}</h2>
                    {{-- Progress: something to anchor against on a long list (prompt 91). --}}
                    <span data-reweigh-progress class="rounded-full bg-surface px-3 py-1 text-sm font-semibold text-ink-muted dark:bg-slate-800 dark:text-slate-300">
                        {{ __(':done de :total pesados', ['done' => $reweighProgress['done'], 'total' => $reweighProgress['total']]) }}
                    </span>
                </div>
                <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">
                    {{-- Copy matches the FILTER: touched since intake (remaining ≠ initial), not "dispensed today" (prompt 91). --}}
                    {{ __('Pesa solo el bote de cada lote de flor tocado desde su entrada e introduce los gramos; las bolsas selladas no se pesan. Si no puedes pesar un bote, márcalo como no contado — su stock no se tocará. El peso esperado se revela solo después de confirmar (recuento a ciegas).') }}
                </p>

                @if ($reweighAsking)
                    {{-- Prompt 360 — ONE question for the whole count, only because something is off. Blind: no jar, no amount,
                         nothing to re-weigh towards; the variances are revealed after, as before. One tap answers it. --}}
                    <div data-reweigh-reason-box x-data="{ other: '' }" class="mt-5 rounded-xl border border-warning/50 bg-surface p-4 dark:bg-slate-900">
                        <h3 class="text-base font-semibold text-ink dark:text-slate-100">{{ __('El recuento no cuadra — ¿qué ha pasado?') }}</h3>
                        <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">{{ __('Elige lo que ha pasado. Una respuesta vale para todo el recuento; el responsable verá el detalle después.') }}</p>
                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            @foreach (\App\Enums\CloseCountReason::cases() as $pick)
                                @continue($pick === \App\Enums\CloseCountReason::OTHER)
                                <x-button variant="secondary" size="lg" wire:click="submitReweigh('{{ $pick->value }}')" data-reweigh-reason-pick="{{ $pick->value }}">{{ $pick->label() }}</x-button>
                            @endforeach
                        </div>
                        <div class="mt-2 flex gap-2">
                            <input type="text" x-model="other" maxlength="120" autocomplete="off" aria-label="{{ __('Otro motivo') }}" placeholder="{{ __('Otro motivo…') }}"
                                   class="h-14 min-w-0 flex-1 rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                            <x-button variant="secondary" size="lg" x-bind:disabled="other.trim() === ''" x-on:click="$wire.submitReweigh('OTHER', other)" data-reweigh-reason-pick="OTHER">{{ __('Otro') }}</x-button>
                        </div>
                        {{-- Prompt 366 — the count never waits on an answer: going on without one is logged for the owner. --}}
                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            <x-button variant="secondary" size="lg" wire:click="cancelClose" class="w-full">{{ __('Cancelar') }}</x-button>
                            <x-button variant="secondary" size="lg" wire:click="submitReweighWithoutReason" class="w-full" data-reweigh-no-reason>{{ __('Seguir sin motivo') }}</x-button>
                        </div>
                    </div>
                @else
                <form wire:submit="submitReweigh" class="mt-5 space-y-4">
                    @foreach ($reweighBatches as $batch)
                        @php $notCounted = $reweighNotCounted[$batch->id] ?? false; @endphp
                        <div wire:key="reweigh-{{ $batch->id }}" data-reweigh-batch="{{ $batch->id }}" class="rounded-xl border border-line bg-surface p-3 dark:border-slate-700 dark:bg-slate-900">
                            <div class="flex items-center justify-between gap-2">
                                <label for="reweigh-{{ $batch->id }}" class="block text-sm font-medium text-ink dark:text-slate-100">
                                    {{ $batch->displayName() }}
                                </label>
                                <button
                                    type="button"
                                    wire:click="toggleNotCounted('{{ $batch->id }}')"
                                    data-reweigh-not-counted-toggle="{{ $batch->id }}"
                                    @class([
                                        'inline-flex min-h-11 shrink-0 items-center rounded-lg px-3 text-xs font-semibold transition',
                                        'bg-warning-fill text-white hover:brightness-90' => $notCounted,
                                        'bg-surface-alt text-ink-muted hover:bg-warning/10 hover:text-warning dark:bg-slate-800 dark:text-slate-400' => ! $notCounted,
                                    ])
                                >
                                    {{ $notCounted ? __('Marcar para contar') : __('No se puede contar') }}
                                </button>
                            </div>

                            @if ($notCounted)
                                <p class="mt-2 text-xs text-warning" data-reweigh-not-counted="{{ $batch->id }}">{{ __('No contado: el stock de este lote no se modificará. Un responsable lo revisará.') }}</p>
                            @else
                                <div class="mt-2 flex items-center gap-2">
                                    <input
                                        id="reweigh-{{ $batch->id }}"
                                        type="text"
                                        inputmode="decimal"
                                        wire:model="reweighCounts.{{ $batch->id }}"
                                        autocomplete="off"
                                        placeholder="0.00"
                                        class="h-14 w-full rounded-xl border border-line bg-surface px-4 text-lg text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                    >
                                    <span class="text-sm text-ink-muted dark:text-slate-400">{{ __('g') }}</span>
                                </div>
                            @endif
                        </div>
                    @endforeach

                    @if ($flashSlot === 'reweigh')
                        @include('livewire.counter.partials.counter-flash', ['anchor' => 'data-till-feedback=reweigh', 'spacing' => '', 'nonce' => $flashSeq, 'reveal' => true])
                    @endif

                    <div class="flex gap-2">
                        <x-button variant="secondary" size="lg" wire:click="cancelClose" class="flex-1">{{ __('Cancelar') }}</x-button>
                        <x-button type="submit" variant="warning" size="lg" class="flex-1">{{ __('Confirmar recuento') }}</x-button>
                    </div>
                </form>
                @endif
            </section>

        @elseif ($closing)
            {{-- ============ Blind count: NO figures shown until the count is confirmed ============ --}}
            <section class="rounded-2xl border border-warning/40 bg-warning/5 p-5 dark:border-warning/30 sm:p-6">
                @if ($reweighResult !== null)
                    {{-- Reweigh revealed: the variances, now that the blind count is committed. --}}
                    <div class="mb-5 rounded-xl border border-line bg-surface p-4 dark:border-slate-700 dark:bg-slate-900">
                        <h3 class="text-sm font-semibold text-ink dark:text-slate-100">{{ __('Recuento de flor registrado') }}</h3>
                        @if ($reweighReason)
                            <p class="mt-1 text-sm text-ink-muted dark:text-slate-400" data-reweigh-reason>{{ __('Motivo: :reason', ['reason' => $reweighReason]) }}</p>
                        @endif
                        <ul class="mt-2 space-y-1 text-sm">
                            @foreach ($reweighResult as $line)
                                <li class="flex items-center justify-between gap-3">
                                    <span class="text-ink-muted dark:text-slate-400">{{ $line['name'] }}</span>
                                    <span class="font-medium text-ink dark:text-slate-100">
                                        @if ($line['not_counted'])
                                            <span class="text-warning" data-reweigh-omission>{{ __('No contado') }}@if ($line['repeated']) · {{ __('otra vez') }}@endif</span>
                                        @elseif ($line['adjusted'])
                                            {{ $line['counted'] }} <span class="text-warning">({{ __('ajuste') }} {{ $line['variance'] }})</span>
                                        @else
                                            {{ $line['counted'] }} <span class="text-success">{{ __('sin diferencia') }}</span>
                                        @endif
                                        @if ($line['unrecorded_topup'] ?? null)
                                            <span class="block text-xs font-normal text-ink-muted dark:text-slate-400" data-unrecorded-topup>{{ __('Rellenado sin registrar: :grams', ['grams' => $line['unrecorded_topup']]) }}</span>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <h2 class="text-lg font-semibold">{{ __('Cierre de caja · arqueo a ciegas') }}</h2>
                <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">
                    {{ __('Cuenta el efectivo del cajón e introduce el total. El importe esperado se revelará solo después de confirmar el recuento.') }}
                </p>
                <p class="mt-2 text-sm text-ink-muted dark:text-slate-400">{{ __('Terminal') }}: <span class="font-medium text-ink dark:text-slate-100">{{ $session->terminal }}</span></p>

                <form wire:submit="submitCount" class="mt-5 space-y-4">
                    <div>
                        <label for="count" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ $session->separate_pots ? __('Dispensario contado (€) — con el fondo de caja') : __('Efectivo contado (€)') }}</label>
                        <input
                            id="count"
                            type="text"
                            inputmode="decimal"
                            wire:model="countInput"
                            autofocus
                            autocomplete="off"
                            placeholder="0.00"
                            class="mt-2 h-14 w-full rounded-xl border border-line bg-surface px-4 text-lg text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        >
                    </div>

                    {{-- Prompt 349 — the bar and fees pots: counted now, or not tonight (their balance carries to the next opening).
                         Still blind: no expected figure appears until the close is confirmed. --}}
                    @if ($session->separate_pots)
                        @foreach (\App\Enums\CashPot::optional() as $pot)
                            <div wire:key="pot-count-{{ $pot->value }}" data-pot-count="{{ $pot->value }}" class="rounded-xl border border-line p-3 dark:border-slate-700">
                                <p class="text-sm font-medium">{{ $pot->label() }}</p>
                                <div class="mt-2 grid grid-cols-2 gap-2">
                                    <button type="button" wire:click="$set('potCountNow.{{ $pot->value }}', true)" data-pot-count-now
                                            @class(['h-11 rounded-lg border text-sm font-semibold', 'border-brand bg-brand-tint text-brand' => $potCountNow[$pot->value] ?? false, 'border-line text-ink-muted dark:border-slate-700' => ! ($potCountNow[$pot->value] ?? false)])>{{ __('Contar ahora') }}</button>
                                    <button type="button" wire:click="$set('potCountNow.{{ $pot->value }}', false)" data-pot-count-skip
                                            @class(['h-11 rounded-lg border text-sm font-semibold', 'border-brand bg-brand-tint text-brand' => ! ($potCountNow[$pot->value] ?? false), 'border-line text-ink-muted dark:border-slate-700' => $potCountNow[$pot->value] ?? false])>{{ __('No se cuenta hoy') }}</button>
                                </div>
                                @if ($potCountNow[$pot->value] ?? false)
                                    <label for="pot-count-{{ $pot->value }}" class="mt-2 block text-xs text-ink-muted dark:text-slate-400">{{ __(':pot contado (€)', ['pot' => $pot->label()]) }}</label>
                                    <input id="pot-count-{{ $pot->value }}" type="text" inputmode="decimal" wire:model="potCountInput.{{ $pot->value }}" autocomplete="off" placeholder="0.00"
                                           class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                                @endif
                            </div>
                        @endforeach
                    @endif

                    {{-- Prompt 366 — a note is never required: the close never waits on one. Offered, with no amount (still blind);
                         a difference beyond the tolerance is logged for the owner either way. --}}
                    <div wire:key="close-note" data-close-note>
                        <label for="note" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('¿Quieres dejar una nota? (opcional)') }}</label>
                        <textarea
                            id="note"
                            wire:model="closeNote"
                            rows="2"
                            maxlength="500"
                            placeholder="{{ __('Por ejemplo: pagado un proveedor, cambio dado de más…') }}"
                            class="mt-2 w-full rounded-xl border border-line bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        ></textarea>
                    </div>

                    @if ($flashSlot === 'count')
                        @include('livewire.counter.partials.counter-flash', ['anchor' => 'data-till-feedback=count', 'spacing' => '', 'nonce' => $flashSeq, 'reveal' => true])
                    @endif

                    <div class="flex gap-2">
                        <button
                            type="button"
                            wire:click="cancelClose"
                            class="h-14 flex-1 rounded-xl border border-line bg-surface-alt px-6 text-base font-semibold text-ink transition hover:bg-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700"
                        >
                            {{ __('Cancelar') }}
                        </button>
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            class="h-14 flex-1 rounded-xl bg-brand px-6 text-base font-semibold text-white transition hover:bg-brand-dark focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:opacity-60"
                        >
                            {{ __('Confirmar recuento') }}
                        </button>
                    </div>
                </form>
            </section>
        @else
            {{-- ============ Open session: the LIVE summary + movements + close ============ --}}
            @php $b = $breakdown; @endphp

            {{-- Two devices, one drawer (prompt 236). This device was sent here with no till open; another
                 terminal opened the sede's shared drawer first, so the precondition is now met elsewhere and
                 the open form has been replaced by this summary. Offer the one thing the operator came for:
                 continue to where they were heading. Only when the guard actually stashed a destination —
                 otherwise this is just the ordinary till screen, reached on purpose. --}}
            @if ($this->pendingContinueUrl())
                <div data-till-continue class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-brand/30 bg-brand-tint px-4 py-3 dark:border-brand/40 dark:bg-slate-800">
                    <p class="text-sm font-medium text-brand-dark dark:text-slate-200">{{ __('Ya hay una caja abierta en esta sede.') }}</p>
                    <button
                        type="button"
                        wire:click="continueToIntended"
                        data-till-continue-action
                        class="inline-flex min-h-[2.75rem] items-center justify-center rounded-xl bg-brand px-6 text-sm font-semibold text-white transition hover:bg-brand-dark"
                    >{{ __('Continuar') }}</button>
                </div>
            @endif

            {{-- Prompt 186: while a handover count is being taken the breakdown is withheld, exactly as the
                 close-out withholds it. The whole summary section goes with it — leaving the drawer's
                 expected figure on screen a few centimetres above the count box would make the "blind"
                 count blind in name only. --}}
            @if ($b !== null)
            <section class="rounded-2xl border border-line bg-surface p-5 dark:border-slate-800 dark:bg-slate-900 sm:p-6">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold">{{ __('Caja abierta') }}</h2>
                        <p class="mt-0.5 text-sm text-ink-muted dark:text-slate-400">
                            {{ __('Terminal') }}: <span class="font-medium text-ink dark:text-slate-100">{{ $session->terminal }}</span>
                        </p>
                        <p class="text-sm text-ink-muted dark:text-slate-400">
                            {{ __('Abierta') }}: {{ local_datetime($session->opened_at, 'd/m/Y H:i') }}@if ($session->openedBy) · {{ $session->openedBy->name }} @endif
                        </p>
                    </div>
                    <span class="shrink-0 rounded-full border border-warning/30 bg-warning/10 px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide text-warning">{{ __('Abierta') }}</span>
                </div>

                {{-- Post-296 audit — the drawer's figures reach the browser only with someone at the PIN (the section, its
                     header and the "identify yourself" notice stay). --}}
                @if ($this->hasOperator())
                <dl class="mt-5 divide-y divide-line text-sm dark:divide-slate-800">
                    <div class="flex items-center justify-between py-2">
                        <dt class="text-ink-muted dark:text-slate-400">{{ __('Fondo de caja') }}</dt>
                        <dd class="font-medium tabular-nums">{{ $this->money($b['float']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between py-2">
                        <dt class="text-ink-muted dark:text-slate-400">{{ __('Dispensación en efectivo') }}</dt>
                        <dd class="font-medium tabular-nums">{{ $this->money($b['cash_contributions']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 py-2">
                        <dt class="text-ink-muted dark:text-slate-400">
                            {{ __('Contribuciones con monedero') }}
                            <span class="ml-1 whitespace-nowrap rounded-full border border-line px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-ink-muted dark:border-slate-700 dark:text-slate-400">{{ __('Excluido del cajón') }}</span>
                        </dt>
                        <dd class="tabular-nums text-ink-muted dark:text-slate-400">{{ $this->money($b['wallet_contributions']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between py-2">
                        <dt class="text-ink-muted dark:text-slate-400">{{ __('Barra y tienda en efectivo') }}@if ($b['separate_pots']) <span class="ml-1 whitespace-nowrap rounded-full border border-line px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-ink-muted dark:border-slate-700 dark:text-slate-400">{{ __('Bote de la barra') }}</span>@endif</dt>
                        <dd class="font-medium tabular-nums">{{ $this->money($b['bar_cash']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between py-2">
                        <dt class="text-ink-muted dark:text-slate-400">{{ __('Recargas de monedero') }}</dt>
                        <dd class="font-medium tabular-nums">{{ $this->money($b['top_ups']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between py-2">
                        <dt class="text-ink-muted dark:text-slate-400">{{ __('Devoluciones') }}</dt>
                        <dd class="font-medium tabular-nums">{{ $this->money($b['refunds']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between py-2">
                        <dt class="text-ink-muted dark:text-slate-400">{{ __('Cuotas en efectivo') }}@if ($b['separate_pots']) <span class="ml-1 whitespace-nowrap rounded-full border border-line px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-ink-muted dark:border-slate-700 dark:text-slate-400">{{ __('Bote de cuotas') }}</span>@endif</dt>
                        <dd class="font-medium tabular-nums">{{ $this->money($b['fees_cash']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between py-2">
                        <dt class="text-ink-muted dark:text-slate-400">{{ __('Entradas de efectivo') }}</dt>
                        <dd class="font-medium tabular-nums">{{ $this->money($b['cash_in']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between py-2">
                        <dt class="text-ink-muted dark:text-slate-400">{{ __('Salidas de efectivo') }}</dt>
                        <dd class="font-medium tabular-nums">{{ $this->money($b['cash_out']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between py-2">
                        <dt class="text-ink-muted dark:text-slate-400">{{ __('Ingresado en banco') }}</dt>
                        <dd class="font-medium tabular-nums">{{ $this->money($b['banked']) }}</dd>
                    </div>
                    <div class="py-2">
                        <div class="flex items-center justify-between">
                            <dt class="text-ink-muted dark:text-slate-400">{{ __('Caja chica') }}</dt>
                            <dd class="font-medium tabular-nums">{{ $this->money($b['petty_cash']) }}</dd>
                        </div>
                        @include('livewire.counter.partials.petty-cash-items', ['items' => $b['petty_cash_items']])
                    </div>
                </dl>

                <div class="mt-4 flex items-center justify-between rounded-xl bg-surface-alt px-4 py-3 dark:bg-slate-800" data-till-expected>
                    <span class="font-semibold">{{ __('Efectivo esperado en el cajón') }}</span>
                    <span class="text-lg font-bold tabular-nums">{{ $this->money($b['expected']) }}</span>
                </div>
                {{-- Prompt 349 — with separate pots the headline is the DISPENSARY pot (the float is its float); the bar and the
                     fees pots are their own lines, never added in, and say since when they have gone uncounted. --}}
                @if ($b['separate_pots'])
                    <dl class="mt-2 space-y-1 px-1 text-sm" data-till-pots>
                        @foreach (\App\Enums\CashPot::optional() as $pot)
                            <div class="flex items-baseline justify-between gap-3" data-till-pot="{{ $pot->value }}">
                                <dt class="text-ink-muted dark:text-slate-400">
                                    {{ __(':pot: esperado', ['pot' => $pot->label()]) }}
                                    @if ($since = $uncountedSince[$pot->value] ?? null)
                                        <span class="text-xs">· {{ __('sin contar desde el :date', ['date' => local_datetime($since, 'd/m', $location)]) }}</span>
                                    @endif
                                </dt>
                                <dd class="font-medium tabular-nums">{{ $this->money($b['pots'][$pot->value]['expected']) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
                @endif
            </section>

            {{-- Cash movement --}}
            {{-- The "record something" panels sit SIDE BY SIDE from lg (design audit). There were three;
                 fee collection left for Socios in prompt 201, so there are two — and two is what this grid
                 was always shaped for. A 2-column row of three panels left one alone on the second line
                 with the other half empty; two fills the row exactly, which is why the layout reads as a
                 decision rather than as something with a hole in it.

                 Measured before the audit: the Caja was the only counter screen not using its width — a
                 672px column in a 1440px viewport, so the page ran to 1811px. The summary above and the
                 close-out below stay full width, because each is the whole job when it is on screen — and
                 the blind count in particular must not share a viewport with anything. --}}
            <div class="grid gap-5 lg:grid-cols-2 lg:items-start">
            <section class="rounded-2xl border border-line bg-surface p-5 dark:border-slate-800 dark:bg-slate-900 sm:p-6">
                <h3 class="text-base font-semibold">{{ __('Registrar movimiento de efectivo') }}</h3>
                @unless ($this->hasOperator()) @include('livewire.counter.partials.needs-operator') @endunless
                <form wire:submit="recordMovement" class="mt-4 grid gap-3 sm:grid-cols-2">
                    <fieldset @disabled(! $this->hasOperator()) class="contents">
                    <div>
                        <label for="movementType" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Tipo') }}</label>
                        <select
                            id="movementType"
                            wire:model="movementType"
                            class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-3 text-base text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        >
                            <option value="IN">{{ \App\Enums\CashMovementType::IN->shortLabel() }}</option>
                            <option value="OUT">{{ \App\Enums\CashMovementType::OUT->shortLabel() }}</option>
                            {{-- Banking cash out is gated on cash.bank (prompt 81); petty cash has its own audited form below. --}}
                            @if ($this->canBankCash())
                                <option value="BANKED">{{ \App\Enums\CashMovementType::BANKED->shortLabel() }}</option>
                            @endif
                        </select>
                    </div>
                    <div>
                        <label for="movementAmount" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Importe (€)') }}</label>
                        <input
                            id="movementAmount"
                            type="text"
                            inputmode="decimal"
                            wire:model="movementAmount"
                            autocomplete="off"
                            placeholder="0.00"
                            class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        >
                    </div>
                    @if ($session?->separate_pots)
                        {{-- Prompt 349 — which pot it comes out of (or goes into). Emptying the bar or fees pot is a Salida or an
                             Ingreso en banco from ITS pot. --}}
                        <div class="sm:col-span-2" data-movement-pot>
                            <label for="movementPot" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Bote') }}</label>
                            <select id="movementPot" wire:model="movementPot"
                                    class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-3 text-base text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                                @foreach (\App\Enums\CashPot::cases() as $pot)
                                    <option value="{{ $pot->value }}">{{ $pot->label() }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">{{ __('Para retirar el dinero de la barra o de las cuotas, elige su bote.') }}</p>
                        </div>
                    @endif
                    <div class="sm:col-span-2">
                        <label for="movementReason" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Motivo') }}</label>
                        <input
                            id="movementReason"
                            type="text"
                            wire:model="movementReason"
                            autocomplete="off"
                            placeholder="{{ __('Ej. cambio, pago a proveedor…') }}"
                            class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        >
                    </div>
                    <div class="sm:col-span-2">
                        {{-- The answer, beside the button that asked (prompt 279) — never only at the top of the page. --}}
                        @if ($flashSlot === 'movement')
                            @include('livewire.counter.partials.counter-flash', ['anchor' => 'data-till-feedback=movement', 'spacing' => 'mb-3', 'nonce' => $flashSeq, 'reveal' => true])
                        @endif
                        {{-- Double-tap guard: disabled, and saying so, while THIS request is in flight. --}}
                        <x-button type="submit" class="w-full" wire:loading.attr="disabled" wire:target="recordMovement">
                            <span wire:loading.remove wire:target="recordMovement">{{ __('Registrar movimiento') }}</span>
                            <span wire:loading wire:target="recordMovement">{{ __('Registrando…') }}</span>
                        </x-button>
                    </div>
                    </fieldset>
                </form>
            </section>

            {{-- Gasto de caja (petty cash) — only staff who may record expenses.
                 Rendered only in the open-session branch, so it never appears during the
                 blind count (which keeps the expected figure hidden). --}}
            @if ($this->userCan('expenses.record'))
                <section class="rounded-2xl border border-line bg-surface p-5 dark:border-slate-800 dark:bg-slate-900 sm:p-6">
                    <h3 class="text-base font-semibold">{{ __('Registrar gasto de caja') }}</h3>
                    <p class="mt-0.5 text-sm text-ink-muted dark:text-slate-400">{{ __('Sale del efectivo del cajón (caja chica).') }}</p>
                    @unless ($this->hasOperator()) @include('livewire.counter.partials.needs-operator') @endunless
                    <form wire:submit="recordExpense" class="mt-4 grid gap-3 sm:grid-cols-2">
                        <fieldset @disabled(! $this->hasOperator()) class="contents">
                        <div>
                            <label for="expenseCategory" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Categoría') }}</label>
                            <select
                                id="expenseCategory"
                                wire:model="expenseCategoryId"
                                class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-3 text-base text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                                <option value="">{{ __('Elige una categoría') }}</option>
                                @foreach ($expenseCategories as $category)
                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="expenseAmount" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Importe (€)') }}</label>
                            <input
                                id="expenseAmount"
                                type="text"
                                inputmode="decimal"
                                wire:model="expenseAmount"
                                autocomplete="off"
                                placeholder="0.00"
                                class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                        </div>
                        <div class="sm:col-span-2">
                            <label for="expenseNote" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Nota') }}</label>
                            <input
                                id="expenseNote"
                                type="text"
                                wire:model="expenseNote"
                                autocomplete="off"
                                placeholder="{{ __('Ej. bolsas, guantes…') }}"
                                class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                        </div>
                        <div class="sm:col-span-2">
                            @if ($flashSlot === 'expense')
                                @include('livewire.counter.partials.counter-flash', ['anchor' => 'data-till-feedback=expense', 'spacing' => 'mb-3', 'nonce' => $flashSeq, 'reveal' => true])
                            @endif
                            <x-button type="submit" class="w-full" wire:loading.attr="disabled" wire:target="recordExpense">
                                <span wire:loading.remove wire:target="recordExpense">{{ __('Registrar gasto') }}</span>
                                <span wire:loading wire:target="recordExpense">{{ __('Registrando…') }}</span>
                            </x-button>
                        </div>
                        </fieldset>
                    </form>
                </section>
            @endif
            {{-- Fee collection LEFT this screen (prompt 201). It was the only panel on the caja that began
                 by asking you to find a person — its own member lookup, on a screen otherwise about the
                 drawer — and Socios does that job better, showing the socio's record, what they owe and
                 their tier before any money is taken.

                 The line below is deliberately not a link. The tab strip is already on screen and Socios is
                 one tap away from it; a second route to the same place is the duplication the counter has
                 twice been cleaned of (189, 194). What an operator who used to do this here needs is to be
                 told WHERE it went, once, not given another button.

                 The drawer invariant is unchanged: a CASH fee still needs an open till, and Socios resolves
                 the same one through SelectTillSession, so it still lands in this drawer and still shows in
                 the arqueo as «Cuotas en efectivo». That is asserted, not assumed. --}}
            @if ($this->userCan('membership.fee.collect'))
                <p data-fee-moved class="rounded-2xl border border-dashed border-line px-4 py-3 text-sm text-ink-muted dark:border-slate-700 dark:text-slate-400">
                    {{ __('Las cuotas se cobran en Socios, donde ves la ficha del socio y lo que debe. El efectivo sigue entrando en esta caja.') }}
                </p>
            @endif
            </div>

            @endif

            {{-- ============ Prompt 186 — hand the drawer to the next person ============

                 Not a close. The session, the trading day and the arqueo all continue; what changes is who
                 is accountable for the cash. A shift change used to leave two bad options — two arqueos for
                 one day, or two people inside one session and a shortfall belonging to nobody.

                 The count is MANDATORY and BLIND. Nothing here shows the expected figure and the flash that
                 follows says nothing about the variance: telling the outgoing operator would let the next
                 handover be counted to fit. `till.open`, not `till.close` — closing ends the day and is
                 manager-gated for that reason; a handover does neither, and requiring a manager for every
                 shift change would push clubs straight back to sharing a session. --}}
            @if ($this->userCan('till.open'))
                <div data-handover class="mt-4 rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-base font-semibold">{{ __('Cambio de turno') }}</h3>
                        <button
                            type="button"
                            wire:click="toggleHandover"
                            data-handover-toggle
                            aria-expanded="{{ $handoverOpen ? 'true' : 'false' }}"
                            class="inline-flex h-11 items-center rounded-xl border border-line px-4 text-sm font-medium text-ink-muted transition hover:bg-surface-alt dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800"
                        >{{ $handoverOpen ? __('Cancelar') : __('Entregar la caja') }}</button>
                    </div>

                    {{-- A settled handover closes the panel, so its answer sits under the card's heading. --}}
                    @if ($flashSlot === 'handover' && ! $handoverOpen)
                        @include('livewire.counter.partials.counter-flash', ['anchor' => 'data-till-feedback=handover', 'spacing' => 'mt-3', 'nonce' => $flashSeq, 'reveal' => true])
                    @endif

                    @if ($handoverOpen)
                        <p class="mt-2 text-sm text-ink-muted dark:text-slate-400">{{ __('Cuenta el efectivo del cajón y que entre la siguiente persona con su PIN. La caja no se cierra: el día sigue siendo uno.') }}</p>

                        <div class="mt-4 space-y-4">
                            <div>
                                <label for="handover-counted" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Efectivo contado (€)') }}</label>
                                @if ($session?->separate_pots)
                                    {{-- Prompt 349 — the handover counts the dispensary pot only; the bar and fees pots stay as they are. --}}
                                    <p data-handover-dispensary-only class="mt-0.5 text-xs text-ink-muted dark:text-slate-400">{{ __('Solo el bote del dispensario (con el fondo de caja).') }}</p>
                                @endif
                                <input
                                    id="handover-counted"
                                    data-handover-counted
                                    type="text"
                                    inputmode="decimal"
                                    wire:model="handoverCounted"
                                    autocomplete="off"
                                    placeholder="0.00"
                                    class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                >
                                {{-- Blind, exactly like the arqueo: the expected figure is not on this screen. --}}
                                <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">{{ __('Cuenta primero. La diferencia se calcula después y queda en el arqueo del día.') }}</p>
                            </div>

                            <div>
                                <label for="handover-pin" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('PIN de quien entra') }}</label>
                                <input
                                    id="handover-pin"
                                    data-handover-pin
                                    type="password"
                                    inputmode="numeric"
                                    autocomplete="off"
                                    wire:model="handoverPin"
                                    class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base tracking-widest text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                >
                                <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">{{ __('Quien entra se identifica antes de que salgas: así el cajón nunca queda sin responsable.') }}</p>
                            </div>

                            <div>
                                <label for="handover-note" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Nota (opcional)') }}</label>
                                <input id="handover-note" type="text" wire:model="handoverNote" autocomplete="off" class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                            </div>

                            {{-- A refusal (bad count, wrong PIN) renders here, beside the button — prompt 279. --}}
                            @if ($flashSlot === 'handover')
                                @include('livewire.counter.partials.counter-flash', ['anchor' => 'data-till-feedback=handover', 'spacing' => '', 'nonce' => $flashSeq, 'reveal' => true])
                            @endif

                            {{-- Prompt 286 — the incoming person's PIN is checked once: one request in flight, "Comprobando…". --}}
                            <button
                                type="button"
                                x-data="window.counterPinCheck()"
                                x-on:click="checkPin(() => $wire.handOver(), { feedback: false })"
                                x-bind:disabled="checking"
                                x-bind:aria-busy="checking"
                                data-handover-confirm
                                class="h-14 w-full rounded-xl bg-brand text-base font-semibold text-white transition hover:bg-brand-dark focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:opacity-60"
                            ><span x-text="checking ? @js(__('Comprobando…')) : @js(__('Entregar la caja'))">{{ __('Entregar la caja') }}</span></button>
                        </div>
                    @endif

                    {{-- The day's attribution trail. A single-operator day shows one row and reads exactly as
                         it always did. --}}
                    @if ($shifts->count() > 1)
                        <ul data-shift-trail class="mt-4 divide-y divide-line overflow-hidden rounded-xl border border-line text-sm dark:divide-slate-800 dark:border-slate-800">
                            @foreach ($shifts as $shift)
                                <li class="flex items-center justify-between gap-3 px-3 py-2">
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium">{{ $shift->openedBy?->name ?? '—' }}</span>
                                        <span class="block text-xs text-ink-muted dark:text-slate-400">
                                            {{ local_datetime($shift->opened_at, 'H:i') }}–{{ $shift->closed_at ? local_datetime($shift->closed_at, 'H:i') : __('ahora') }}
                                        </span>
                                    </span>
                                    <span class="shrink-0 text-xs font-medium">{{ $shift->status->label() }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            {{-- Close (arqueo) — DEMOTED (prompt 91): a once-a-day, irreversible action must not be the
                 loudest control on a tablet being scrolled mid-shift. A quiet, outlined button (the routine
                 movement/expense/fee actions carry the brand fill instead), and it opens a deliberate
                 multi-step close (reweigh → blind count) rather than committing anything on tap. --}}
            @if ($this->userCan('till.close'))
                <button
                    type="button"
                    wire:click="startClose"
                    data-close-till
                    class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-6 text-base font-semibold text-ink-muted transition hover:border-ink-muted hover:text-ink focus:outline-none focus:ring-2 focus:ring-brand/30 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
                >
                    {{ __('Cerrar caja · arqueo') }}
                </button>
            @else
                <p class="rounded-xl border border-dashed border-line px-4 py-3 text-center text-sm text-ink-muted dark:border-slate-700 dark:text-slate-400">
                    {{ __('Cerrar la caja lo hace alguien con permiso: que se identifique con su PIN.') }}
                </p>
            @endif
        @endif
    @endif
@endif
</div>

{{-- Prompt 23: flag an in-progress blind count / cash entry as unsaved work.

     **Both flags, and this is the ONLY screen that sets `volatile`** (prompt 206). These three are plain
     Livewire properties on a screen with no `PersistsBasket`: a half-typed arqueo does not survive a trip to
     the hub, so Home must ask here even though it must not ask on a POS basket. --}}
@script
<script>
    const sync = () => {
        const s = window.Alpine?.store('counter');
        if (! s) return;
        const typed = ((($wire.countInput ?? '') !== '') || (($wire.movementAmount ?? '') !== '') || (($wire.expenseAmount ?? '') !== ''));
        s.dirty = typed;
        s.volatile = typed;
    };
    ['countInput', 'movementAmount', 'expenseAmount'].forEach((p) => $wire.$watch(p, sync));
    sync();
</script>
@endscript
