<div class="mx-auto flex w-full max-w-2xl flex-col gap-5">
    @include('livewire.counter.partials.operator-strip')

    @if ($noLocation)
        {{-- Intentional empty state: an operator with no assigned sede. Still a 200. --}}
        <div class="rounded-2xl border border-line bg-surface p-8 text-center dark:border-slate-800 dark:bg-slate-900">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-surface-alt text-2xl dark:bg-slate-800">📍</div>
            <h2 class="mt-4 text-lg font-semibold">{{ __('Sin sede asignada') }}</h2>
            <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">
                {{ __('No tienes ninguna sede activa. Pide a un responsable que te asigne una para gestionar la caja.') }}
            </p>
        </div>
    @else
        {{-- Flash --}}
        @if ($flashMessage)
            <div
                wire:key="flash"
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
                    <span class="rounded-full border border-slate-300 bg-surface-alt px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide text-ink-muted dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ __('Cerrada') }}</span>
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
            {{-- ============ No open session: the OPEN form ============ --}}
            <section class="rounded-2xl border border-line bg-surface p-5 dark:border-slate-800 dark:bg-slate-900 sm:p-6">
                <h2 class="text-lg font-semibold">{{ __('Abrir caja') }}</h2>
                <p class="mt-0.5 text-sm text-ink-muted dark:text-slate-400">{{ __('No hay ninguna caja abierta en este terminal.') }}</p>

                <form wire:submit="open" class="mt-5 space-y-4">
                    <div>
                        <label for="terminal" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Terminal') }}</label>
                        <input
                            id="terminal"
                            type="text"
                            wire:model="terminal"
                            autocomplete="off"
                            spellcheck="false"
                            placeholder="{{ __('Ej. POS-1') }}"
                            class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted/60 focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        >
                    </div>
                    <div>
                        <label for="float" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Fondo de caja (€)') }}</label>
                        <input
                            id="float"
                            type="text"
                            inputmode="decimal"
                            wire:model="floatInput"
                            autocomplete="off"
                            placeholder="0,00"
                            class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted/60 focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        >
                    </div>
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        class="h-14 w-full rounded-xl bg-brand text-base font-semibold text-white transition hover:bg-brand-dark focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:opacity-60"
                    >
                        {{ __('Abrir caja') }}
                    </button>
                </form>
            </section>
        @elseif ($closing)
            {{-- ============ Blind count: NO figures shown until the count is confirmed ============ --}}
            <section class="rounded-2xl border border-warning/40 bg-warning/5 p-5 dark:border-warning/30 sm:p-6">
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
                            class="mt-2 h-14 w-full rounded-xl border border-line bg-surface px-4 text-lg text-ink placeholder:text-ink-muted/60 focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
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
                        <dt class="text-ink-muted dark:text-slate-400">{{ __('Barra en efectivo') }}</dt>
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
                <form wire:submit="recordMovement" class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="movementType" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Tipo') }}</label>
                        <select
                            id="movementType"
                            wire:model="movementType"
                            class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-3 text-base text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        >
                            <option value="IN">{{ __('Entrada') }}</option>
                            <option value="OUT">{{ __('Salida') }}</option>
                            <option value="BANKED">{{ __('Ingreso en banco') }}</option>
                            <option value="PETTY_CASH">{{ __('Caja chica') }}</option>
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
                            class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted/60 focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
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
                            class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted/60 focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        >
                    </div>
                    <div class="sm:col-span-2">
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            class="h-12 w-full rounded-xl border border-line bg-surface-alt px-6 text-base font-semibold text-ink transition hover:bg-slate-200 disabled:opacity-60 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700"
                        >
                            {{ __('Registrar movimiento') }}
                        </button>
                    </div>
                </form>
            </section>

            {{-- Gasto de caja (petty cash) — only staff who may record expenses.
                 Rendered only in the open-session branch, so it never appears during the
                 blind count (which keeps the expected figure hidden). --}}
            @can('expenses.record')
                <section class="rounded-2xl border border-line bg-surface p-5 dark:border-slate-800 dark:bg-slate-900 sm:p-6">
                    <h3 class="text-base font-semibold">{{ __('Registrar gasto de caja') }}</h3>
                    <p class="mt-0.5 text-sm text-ink-muted dark:text-slate-400">{{ __('Sale del efectivo del cajón (caja chica).') }}</p>
                    <form wire:submit="recordExpense" class="mt-4 grid gap-3 sm:grid-cols-2">
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
                                class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted/60 focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
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
                                class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted/60 focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                        </div>
                        <div class="sm:col-span-2">
                            <button
                                type="submit"
                                wire:loading.attr="disabled"
                                class="h-12 w-full rounded-xl border border-line bg-surface-alt px-6 text-base font-semibold text-ink transition hover:bg-slate-200 disabled:opacity-60 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700"
                            >
                                {{ __('Registrar gasto') }}
                            </button>
                        </div>
                    </form>
                </section>
            @endcan

            {{-- Close (arqueo) — only a till.close holder may close. --}}
            @can('till.close')
                <button
                    type="button"
                    wire:click="startClose"
                    class="h-16 w-full rounded-xl bg-brand text-lg font-bold text-white transition hover:bg-brand-dark focus:outline-none focus:ring-2 focus:ring-brand/40"
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
</div>

{{-- Prompt 23: flag an in-progress blind count / cash entry as unsaved work. --}}
@script
<script>
    const sync = () => { if (window.Alpine?.store('counter')) window.Alpine.store('counter').dirty = ((($wire.countInput ?? '') !== '') || (($wire.movementAmount ?? '') !== '') || (($wire.expenseAmount ?? '') !== '')); };
    ['countInput', 'movementAmount', 'expenseAmount'].forEach((p) => $wire.$watch(p, sync));
    sync();
</script>
@endscript
