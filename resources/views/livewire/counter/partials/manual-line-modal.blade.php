{{-- The manual bar line's modal — ONE partial for the Bar screen and the Dispensario's Barra tab (prompt 331). The host
     composes AddsManualBarLines (the fields and the rules) and has an `addMiscLine()` that puts the line in its bar basket.
     `heading` names it: "Importe manual" on the Bar, "Línea manual de barra" on the Dispensario (a bar line, never cannabis).

     Opened by any trigger with `$dispatch('manual-line-open')`. Prompt 126: the reason is ONE TAP for the common cases,
     free text as the fallback. Prompt 272: focus moves INTO it and back to the trigger on close, and the back gesture
     closes it instead of leaving the screen (pushState on open, popstate closes). No trap. It closes only on success
     (`misc-added`), so a refusal keeps the operator's input. --}}
@php($heading ??= __('Importe manual'))
<div data-manual-line-modal
     x-data="{
         showMisc: false,
         miscTrigger: null,
         openMisc() {
             this.miscTrigger = document.activeElement
             this.showMisc = true
             history.pushState({ barMisc: true }, '')
             this.$nextTick(() => document.getElementById('misc-desc')?.focus())
         },
         closeMisc() {
             if (! this.showMisc) return
             this.showMisc = false
             if (history.state?.barMisc) history.back()
             this.miscTrigger?.focus?.()
         },
     }"
     x-on:manual-line-open.window="openMisc()"
     x-on:misc-added.window="closeMisc()"
     x-on:popstate.window="if (showMisc) { showMisc = false; miscTrigger?.focus?.() }">
    <div x-show="showMisc" x-cloak @keydown.escape.window="closeMisc()"
         class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-label="{{ $heading }}">
        <div @click.outside="closeMisc()" class="w-full max-w-md rounded-2xl border border-line bg-surface p-5 shadow-xl dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold">{{ $heading }}</h2>
                <button type="button" @click="closeMisc()" aria-label="{{ __('Cerrar') }}" class="flex h-11 w-11 items-center justify-center rounded-lg text-ink-muted hover:bg-black/5 dark:text-slate-400 dark:hover:bg-white/5">✕</button>
            </div>
            <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">{{ __('Un concepto fuera de catálogo (no mueve stock). El motivo queda registrado para poder revisarlo después.') }}</p>

            <div class="mt-3 space-y-3">
                <div>
                    <label for="misc-desc" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Descripción') }}</label>
                    <input id="misc-desc" type="text" wire:model="miscDescription" autocomplete="off" class="mt-1 h-11 w-full rounded-xl border border-line bg-surface px-3 text-base text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                </div>
                <div>
                    <label for="misc-amount" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Importe (€)') }}</label>
                    <input id="misc-amount" type="text" inputmode="decimal" wire:model="miscAmount" autocomplete="off" placeholder="0.00" class="mt-1 h-11 w-full rounded-xl border border-line bg-surface px-3 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                </div>
                <div>
                    <label for="misc-ref" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Motivo') }}</label>
                    <div class="mt-1 flex flex-wrap gap-1.5">
                        @foreach ([__('Producto sin dar de alta'), __('Precio especial'), __('Evento')] as $reason)
                            <button type="button" @click="$wire.set('miscReference', @js($reason))" class="min-h-11 rounded-full border border-line px-3 py-1.5 text-sm text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:text-slate-100 dark:hover:bg-slate-800">{{ $reason }}</button>
                        @endforeach
                    </div>
                    <input id="misc-ref" type="text" wire:model="miscReference" autocomplete="off" placeholder="{{ __('… o escribe un motivo') }}" class="mt-2 h-11 w-full rounded-xl border border-line bg-surface px-3 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                    <p class="mt-1 text-[11px] text-ink-muted dark:text-slate-400">{{ __('Justifica por qué es una línea sin catálogo — se revisa al cerrar la caja.') }}</p>
                </div>
                <x-button size="md" class="w-full" wire:click="addMiscLine">{{ __('Añadir importe manual') }}</x-button>
            </div>
        </div>
    </div>
</div>
