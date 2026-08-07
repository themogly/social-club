<div class="mx-auto flex w-full max-w-2xl flex-col gap-5">
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
            icon="📍"
            :heading="$mustChooseLocation ? __('Elige tu sede') : __('Sin sede asignada')"
            :body="$mustChooseLocation ? __('Trabajas en varias sedes. Selecciona en la barra superior en cuál estás.') : __('No tienes ninguna sede activa. Pide a un responsable que te asigne una para gestionar la caja.')"
        />
    @else
        {{-- Flash --}}
        @if ($flashMessage)
            <div
                wire:key="flash"
                role="{{ $flashType === 'error' ? 'alert' : 'status' }}"
                aria-live="{{ $flashType === 'error' ? 'assertive' : 'polite' }}"
                @class([
                    'flex items-center justify-between gap-3 rounded-xl border px-4 py-3 text-sm font-medium',
                    'border-success/30 bg-success/10 text-success' => $flashType === 'success',
                    'border-warning/30 bg-warning/10 text-warning' => $flashType === 'warning',
                    'border-error/30 bg-error/10 text-error' => $flashType === 'error',
                ])
            >
                <span>{{ $flashMessage }}</span>
                <button type="button" wire:click="$set('flashMessage', null)" aria-label="{{ __('Descartar aviso') }}" class="shrink-0 rounded-md px-2 py-1 opacity-70 hover:opacity-100">✕</button>
            </div>
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
                        <dt class="text-ink-muted dark:text-slate-400">{{ __('Efectivo contado') }}</dt>
                        <dd class="text-lg font-semibold tabular-nums">{{ $this->money($counted ?? 0) }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-ink-muted dark:text-slate-400">{{ __('Efectivo esperado') }}</dt>
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
                class="mx-auto flex min-h-[60vh] w-full max-w-md flex-col justify-center rounded-2xl border border-line bg-surface p-6 text-center dark:border-slate-800 dark:bg-slate-900"
            >
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-surface-alt text-3xl dark:bg-slate-800" aria-hidden="true">💶</div>

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
                        <input
                            id="float"
                            data-till-float
                            type="text"
                            inputmode="decimal"
                            wire:model="floatInput"
                            autocomplete="off"
                            placeholder="0,00"
                            class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        >
                        {{-- The first-ever open has no default and no previous session. That must not be an
                             empty required field with no explanation — so it says why it is empty and where
                             the figure comes from once someone sets one. --}}
                        @if ($this->defaultFloatCents() !== null)
                            <p data-float-default class="mt-1.5 text-xs text-ink-muted dark:text-slate-500">{{ __('Fondo habitual de la sede. Puedes cambiarlo.') }}</p>
                        @else
                            <p data-float-no-default class="mt-1.5 text-xs text-ink-muted dark:text-slate-500">{{ __('Esta sede no tiene fondo por defecto. Escribe el importe con el que abres; un responsable puede fijarlo en Ajustes.') }}</p>
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
                    {{ __('Pesa cada lote de flor tocado desde su entrada e introduce los gramos contados. Si no puedes contar un bote, márcalo como no contado e indica el motivo — su stock no se tocará. El peso esperado se revela solo después de confirmar (recuento a ciegas).') }}
                </p>

                <form wire:submit="submitReweigh" class="mt-5 space-y-4">
                    @foreach ($reweighBatches as $batch)
                        @php $notCounted = $reweighNotCounted[$batch->id] ?? false; @endphp
                        <div wire:key="reweigh-{{ $batch->id }}" data-reweigh-batch="{{ $batch->id }}" class="rounded-xl border border-line bg-surface p-3 dark:border-slate-700 dark:bg-slate-900">
                            <div class="flex items-center justify-between gap-2">
                                <label for="reweigh-{{ $batch->id }}" class="block text-sm font-medium text-ink dark:text-slate-100">
                                    {{ $batch->genetic?->name ?? __('Sin nombre') }} · {{ $batch->batch_no }}
                                </label>
                                <button
                                    type="button"
                                    wire:click="toggleNotCounted('{{ $batch->id }}')"
                                    data-reweigh-not-counted-toggle="{{ $batch->id }}"
                                    @class([
                                        'shrink-0 rounded-lg px-2.5 py-1 text-xs font-semibold transition',
                                        'bg-warning text-white' => $notCounted,
                                        'bg-surface-alt text-ink-muted hover:bg-warning/10 hover:text-warning dark:bg-slate-800 dark:text-slate-400' => ! $notCounted,
                                    ])
                                >
                                    {{ $notCounted ? __('Marcar para contar') : __('No se puede contar') }}
                                </button>
                            </div>

                            @if ($notCounted)
                                <input
                                    type="text"
                                    wire:model="reweighReasons.{{ $batch->id }}"
                                    data-reweigh-reason="{{ $batch->id }}"
                                    autocomplete="off"
                                    placeholder="{{ __('Motivo (p. ej. bote no localizado)') }}"
                                    class="mt-2 h-14 w-full rounded-xl border border-warning/50 bg-warning/5 px-4 text-base text-ink placeholder:text-ink-muted focus:border-warning focus:outline-none focus:ring-2 focus:ring-warning/40 dark:text-slate-100"
                                >
                                <p class="mt-1 text-xs text-warning">{{ __('No contado: el stock de este lote no se modificará. Un responsable lo revisará.') }}</p>
                            @else
                                <div class="mt-2 flex items-center gap-2">
                                    <input
                                        id="reweigh-{{ $batch->id }}"
                                        type="text"
                                        inputmode="decimal"
                                        wire:model="reweighCounts.{{ $batch->id }}"
                                        autocomplete="off"
                                        placeholder="0,00"
                                        class="h-14 w-full rounded-xl border border-line bg-surface px-4 text-lg text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                    >
                                    <span class="text-sm text-ink-muted dark:text-slate-400">{{ __('g') }}</span>
                                </div>
                            @endif
                        </div>
                    @endforeach

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
                            class="h-14 flex-1 rounded-xl bg-warning px-6 text-base font-semibold text-white transition hover:opacity-90"
                        >
                            {{ __('Confirmar recuento') }}
                        </button>
                    </div>
                </form>
            </section>

        @elseif ($closing)
            {{-- ============ Blind count: NO figures shown until the count is confirmed ============ --}}
            <section class="rounded-2xl border border-warning/40 bg-warning/5 p-5 dark:border-warning/30 sm:p-6">
                @if ($reweighResult !== null)
                    {{-- Reweigh revealed: the variances, now that the blind count is committed. --}}
                    <div class="mb-5 rounded-xl border border-line bg-surface p-4 dark:border-slate-700 dark:bg-slate-900">
                        <h3 class="text-sm font-semibold text-ink dark:text-slate-100">{{ __('Recuento de flor registrado') }}</h3>
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
                        <label for="count" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Efectivo contado (€)') }}</label>
                        <input
                            id="count"
                            type="text"
                            inputmode="decimal"
                            wire:model="countInput"
                            autofocus
                            autocomplete="off"
                            placeholder="0,00"
                            class="mt-2 h-14 w-full rounded-xl border border-line bg-surface px-4 text-lg text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        >
                    </div>

                    @if ($needsNote)
                        <div wire:key="close-note">
                            <label for="note" class="block text-sm font-medium text-warning">{{ __('Nota (obligatoria: la diferencia supera la tolerancia)') }}</label>
                            <textarea
                                id="note"
                                wire:model="closeNote"
                                rows="2"
                                class="mt-2 w-full rounded-xl border border-warning/40 bg-surface px-3 py-2 text-sm focus:border-warning focus:outline-none focus:ring-2 focus:ring-warning/40 dark:bg-slate-950"
                            ></textarea>
                        </div>
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
                            {{ __('Abierta') }}: {{ $session->opened_at?->format('d/m/Y H:i') }}@if ($session->openedBy) · {{ $session->openedBy->name }} @endif
                        </p>
                    </div>
                    <span class="shrink-0 rounded-full border border-warning/30 bg-warning/10 px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide text-warning">{{ __('Abierta') }}</span>
                </div>

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
                            <span class="ml-1 whitespace-nowrap rounded-full border border-line px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-ink-muted dark:border-slate-700 dark:text-slate-500">{{ __('Excluido del cajón') }}</span>
                        </dt>
                        <dd class="tabular-nums text-ink-muted dark:text-slate-500">{{ $this->money($b['wallet_contributions']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between py-2">
                        <dt class="text-ink-muted dark:text-slate-400">{{ __('Barra y tienda en efectivo') }}</dt>
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
                        <dt class="text-ink-muted dark:text-slate-400">{{ __('Cuotas en efectivo') }}</dt>
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
                    <div class="flex items-center justify-between py-2">
                        <dt class="text-ink-muted dark:text-slate-400">{{ __('Caja chica') }}</dt>
                        <dd class="font-medium tabular-nums">{{ $this->money($b['petty_cash']) }}</dd>
                    </div>
                </dl>

                <div class="mt-4 flex items-center justify-between rounded-xl bg-surface-alt px-4 py-3 dark:bg-slate-800">
                    <span class="font-semibold">{{ __('Efectivo esperado en el cajón') }}</span>
                    <span class="text-lg font-bold tabular-nums">{{ $this->money($b['expected']) }}</span>
                </div>
            </section>

            {{-- Cash movement --}}
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
                            <option value="IN">{{ __('Entrada') }}</option>
                            <option value="OUT">{{ __('Salida') }}</option>
                            {{-- Banking cash out is gated on cash.bank (prompt 81); petty cash has its own audited form below. --}}
                            @if ($this->canBankCash())
                                <option value="BANKED">{{ __('Ingreso en banco') }}</option>
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
                            placeholder="0,00"
                            class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        >
                    </div>
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
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            class="h-12 w-full rounded-xl bg-brand px-6 text-base font-semibold text-white transition hover:bg-brand-dark focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:opacity-60"
                        >
                            {{ __('Registrar movimiento') }}
                        </button>
                    </div>
                    </fieldset>
                </form>
            </section>

            {{-- Gasto de caja (petty cash) — only staff who may record expenses.
                 Rendered only in the open-session branch, so it never appears during the
                 blind count (which keeps the expected figure hidden). --}}
            @can('expenses.record')
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
                                placeholder="0,00"
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
                            <button
                                type="submit"
                                wire:loading.attr="disabled"
                                class="h-12 w-full rounded-xl bg-brand px-6 text-base font-semibold text-white transition hover:bg-brand-dark focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:opacity-60"
                            >
                                {{ __('Registrar gasto') }}
                            </button>
                        </div>
                        </fieldset>
                    </form>
                </section>
            @endcan

            {{-- Cobrar cuota — record a membership fee against the open drawer. The ONLY path that
                 clears unpaid_fee at the counter (prompt 46). Gated on membership.fee.collect. --}}
            @can('membership.fee.collect')
                <section class="rounded-2xl border border-line bg-surface p-5 dark:border-slate-800 dark:bg-slate-900 sm:p-6">
                    <h3 class="text-base font-semibold">{{ __('Cobrar cuota') }}</h3>
                    <p class="mt-0.5 text-sm text-ink-muted dark:text-slate-400">{{ __('Registra el pago de la cuota de socio.') }}</p>
                    @unless ($this->hasOperator()) @include('livewire.counter.partials.needs-operator') @endunless
                    <fieldset @disabled(! $this->hasOperator()) class="contents">

                    @if ($feeMember === null)
                        {{-- Prompt 194 — the SAME lookup as the door and the dispensary. The caja's own name
                             box could not resolve a scanned card. No autofocus: this panel is one of several
                             on a screen whose main job is the drawer, not identifying anybody. --}}
                        <div class="mt-4">
                            @include('livewire.counter.partials.member-lookup', ['autofocus' => false])
                        </div>
                    @else
                        <div class="mt-4 flex items-center justify-between rounded-xl bg-surface-alt px-4 py-3 dark:bg-slate-800">
                            <div>
                                <p class="font-semibold">{{ $feeMember->fullName() }}</p>
                                <p class="text-sm text-ink-muted dark:text-slate-400">{{ $feeMember->member_no }}</p>
                            </div>
                            <button type="button" wire:click="clearFeeMember" class="rounded-lg px-3 py-1.5 text-sm font-medium text-ink-muted hover:bg-black/5 dark:text-slate-400 dark:hover:bg-white/5">{{ __('Cambiar') }}</button>
                        </div>

                        @if ($feeOwedCents === null)
                            <p class="mt-3 rounded-xl border border-warning/30 bg-warning/10 px-4 py-3 text-sm font-medium text-warning">{{ __('Este socio no tiene cuota pendiente en esta sede.') }}</p>
                        @else
                            <p class="mt-3 text-sm">{{ __('Cuota pendiente:') }} <span class="font-bold tabular-nums">{{ $this->money($feeOwedCents) }}</span></p>
                            <form wire:submit="collectFee" class="mt-3 grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label for="feeAmount" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Importe a cobrar (€)') }}</label>
                                    <input id="feeAmount" type="text" inputmode="decimal" wire:model="feeAmount" autocomplete="off" placeholder="0,00"
                                           class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                                </div>
                                <div>
                                    <label for="feeMethod" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Método') }}</label>
                                    <select id="feeMethod" wire:model="feeMethod"
                                            class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-3 text-base text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                                        <option value="CASH">{{ __('Efectivo') }}</option>
                                        <option value="WALLET">{{ __('Monedero') }}</option>
                                    </select>
                                </div>
                                <div class="sm:col-span-2">
                                    <button type="submit" wire:loading.attr="disabled"
                                            class="h-12 w-full rounded-xl bg-brand px-6 text-base font-semibold text-white transition hover:bg-brand-dark focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:opacity-60">
                                        {{ __('Cobrar cuota') }}
                                    </button>
                                </div>
                            </form>
                        @endif
                    @endif
                    </fieldset>
                </section>
            @endcan

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
            @can('till.open')
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

                    @if ($handoverOpen)
                        <p class="mt-2 text-sm text-ink-muted dark:text-slate-400">{{ __('Cuenta el efectivo del cajón y que entre la siguiente persona con su PIN. La caja no se cierra: el día sigue siendo uno.') }}</p>

                        <div class="mt-4 space-y-4">
                            <div>
                                <label for="handover-counted" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Efectivo contado (€)') }}</label>
                                <input
                                    id="handover-counted"
                                    data-handover-counted
                                    type="text"
                                    inputmode="decimal"
                                    wire:model="handoverCounted"
                                    autocomplete="off"
                                    placeholder="0,00"
                                    class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                >
                                {{-- Blind, exactly like the arqueo: the expected figure is not on this screen. --}}
                                <p class="mt-1 text-xs text-ink-muted dark:text-slate-500">{{ __('Cuenta primero. La diferencia se calcula después y queda en el arqueo del día.') }}</p>
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
                                <p class="mt-1 text-xs text-ink-muted dark:text-slate-500">{{ __('Quien entra se identifica antes de que salgas: así el cajón nunca queda sin responsable.') }}</p>
                            </div>

                            <div>
                                <label for="handover-note" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Nota (opcional)') }}</label>
                                <input id="handover-note" type="text" wire:model="handoverNote" autocomplete="off" class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                            </div>

                            <button
                                type="button"
                                wire:click="handOver"
                                data-handover-confirm
                                wire:loading.attr="disabled"
                                class="h-14 w-full rounded-xl bg-brand text-base font-semibold text-white transition hover:bg-brand-dark focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:opacity-60"
                            >{{ __('Entregar la caja') }}</button>
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
                                            {{ $shift->opened_at?->format('H:i') }}–{{ $shift->closed_at?->format('H:i') ?? __('ahora') }}
                                        </span>
                                    </span>
                                    <span class="shrink-0 text-xs font-medium">{{ $shift->status->label() }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endcan

            {{-- Close (arqueo) — DEMOTED (prompt 91): a once-a-day, irreversible action must not be the
                 loudest control on a tablet being scrolled mid-shift. A quiet, outlined button (the routine
                 movement/expense/fee actions carry the brand fill instead), and it opens a deliberate
                 multi-step close (reweigh → blind count) rather than committing anything on tap. --}}
            @can('till.close')
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
                    {{ __('Solo un responsable con permiso (till.close) puede cerrar la caja.') }}
                </p>
            @endcan
        @endif
    @endif
@endif
</div>

{{-- Prompt 23: flag an in-progress blind count / cash entry as unsaved work. --}}
@script
<script>
    const sync = () => { if (window.Alpine?.store('counter')) window.Alpine.store('counter').dirty = ((($wire.countInput ?? '') !== '') || (($wire.movementAmount ?? '') !== '') || (($wire.expenseAmount ?? '') !== '')); };
    ['countInput', 'movementAmount', 'expenseAmount'].forEach((p) => $wire.$watch(p, sync));
    sync();
</script>
@endscript
