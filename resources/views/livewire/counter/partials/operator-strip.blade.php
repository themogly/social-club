{{--
    Shared operator identity strip (prompt 26). @include-d by every counter screen so
    there is ONE implementation — wire: directives bind to the enclosing Livewire
    component, which uses the IdentifiesOperator trait. Shows who is currently working
    and a PIN pad to unlock / switch operator. The PIN never leaves the pad except on
    submit; it is verified server-side (hashed, rate-limited) and never rendered back.
--}}
<div data-operator-strip class="border-b border-line bg-surface-alt px-4 py-2 dark:border-slate-800 dark:bg-slate-900/60 sm:px-6">
    <div class="flex items-center justify-between gap-3">
        @if ($this->hasOperator())
            <p class="flex items-center gap-2 text-sm">
                <span class="inline-block h-2.5 w-2.5 rounded-full bg-success" aria-hidden="true"></span>
                <span class="text-ink-muted dark:text-slate-400">{{ __('Trabajando') }}:</span>
                <span data-operator-name class="font-semibold">{{ $this->currentOperatorName() }}</span>
            </p>
            <button
                type="button"
                wire:click="switchOperator"
                data-operator-switch
                class="rounded-lg px-3 py-1.5 text-sm font-medium text-ink-muted transition hover:bg-brand-tint hover:text-brand dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"
            >{{ __('Cambiar') }}</button>
        @else
            <p class="flex items-center gap-2 text-sm">
                <span class="inline-block h-2.5 w-2.5 rounded-full bg-warning" aria-hidden="true"></span>
                <span class="font-medium text-warning">{{ __('Sin operador identificado') }}</span>
            </p>
            <button
                type="button"
                wire:click="openOperatorPanel"
                data-operator-identify
                class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-brand-dark"
            >{{ __('Identificarse') }}</button>
        @endif
    </div>

    @if ($operatorPanelOpen)
        <div
            data-operator-pad
            x-data="{ pin: '', push(d){ if (this.pin.length < 8) this.pin += d }, back(){ this.pin = this.pin.slice(0, -1) }, clear(){ this.pin = '' }, submit(){ if (this.pin === '') return; $wire.operatorPin = this.pin; this.pin = ''; $wire.unlockOperator(); } }"
            @keydown.window.enter="submit()"
            class="mt-3 rounded-xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900"
        >
            <div class="mx-auto max-w-xs">
                <p class="mb-2 text-center text-sm font-medium">{{ __('Introduce tu PIN') }}</p>

                {{-- Masked, client-side display of the digits entered so far. --}}
                <div class="mb-3 flex h-11 items-center justify-center gap-1.5 rounded-lg border border-line bg-surface-alt dark:border-slate-700 dark:bg-slate-800" aria-hidden="true">
                    <template x-for="i in pin.length" :key="i">
                        <span class="h-2.5 w-2.5 rounded-full bg-ink dark:bg-slate-200"></span>
                    </template>
                    <span x-show="pin.length === 0" class="text-sm text-ink-muted dark:text-slate-500">••••</span>
                </div>

                @if ($operatorFeedback !== null)
                    <p data-operator-feedback class="mb-3 rounded-lg bg-error/10 px-3 py-2 text-center text-sm font-medium text-error">{{ $operatorFeedback }}</p>
                @endif

                @if ($this->operatorLockedOut())
                    <p class="mb-3 text-center text-sm text-ink-muted dark:text-slate-400">{{ __('Demasiados intentos. Espera un momento antes de reintentar.') }}</p>
                @endif

                <div class="grid grid-cols-3 gap-2">
                    @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9'] as $digit)
                        <button type="button" @click="push('{{ $digit }}')" class="rounded-lg border border-line py-3 text-lg font-semibold transition hover:bg-brand-tint hover:text-brand dark:border-slate-700 dark:hover:bg-slate-800 dark:hover:text-white">{{ $digit }}</button>
                    @endforeach
                    <button type="button" @click="clear()" class="rounded-lg border border-line py-3 text-sm font-medium text-ink-muted transition hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">{{ __('Borrar') }}</button>
                    <button type="button" @click="push('0')" class="rounded-lg border border-line py-3 text-lg font-semibold transition hover:bg-brand-tint hover:text-brand dark:border-slate-700 dark:hover:bg-slate-800 dark:hover:text-white">0</button>
                    <button type="button" @click="back()" aria-label="{{ __('Retroceso') }}" class="rounded-lg border border-line py-3 text-lg transition hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">⌫</button>
                </div>

                <div class="mt-3 flex gap-2">
                    <button type="button" wire:click="closeOperatorPanel" class="flex-1 rounded-lg border border-line py-2.5 text-sm font-medium text-ink-muted transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800">{{ __('Cancelar') }}</button>
                    <button type="button" data-operator-unlock @click="submit()" class="flex-1 rounded-lg bg-brand py-2.5 text-sm font-semibold text-white transition hover:bg-brand-dark">{{ __('Desbloquear') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
