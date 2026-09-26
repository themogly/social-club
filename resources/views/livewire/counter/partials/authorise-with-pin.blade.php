{{-- Prompt 265 — the breach panel for an operator who cannot authorise it themselves: plain words (never a permission
     key), and "Autorizar con PIN" — the manager beside them types their OWN PIN and a reason for this one act. The
     staff member stays the operator of record and stays identified; the basket is untouched.
     Params: `action` (the component method), `reasonModel` (the bound reason property). --}}
<div data-authorise-with-pin x-data="{ open: false }" class="mt-2">
    <p class="text-sm text-ink-muted dark:text-slate-400">{{ __('Hace falta que un encargado autorice esta excepción.') }}</p>
    <x-button type="button" variant="secondary" size="md" class="mt-2 w-full" x-show="! open" @click="open = true">{{ __('Autorizar con PIN') }}</x-button>
    <div x-show="open" x-cloak class="mt-2 space-y-2">
        <textarea wire:model="{{ $reasonModel }}" rows="2" placeholder="{{ __('Motivo de la excepción (queda registrado)') }}" class="w-full rounded-xl border border-warning/40 bg-surface px-3 py-2 text-sm focus:border-warning focus:outline-none focus:ring-2 focus:ring-warning/40 dark:bg-slate-950"></textarea>
        <label class="block text-xs font-medium text-ink-muted dark:text-slate-400" for="authoriser-pin-{{ $action }}">{{ __('PIN de quien autoriza') }}</label>
        <input id="authoriser-pin-{{ $action }}" type="password" inputmode="numeric" autocomplete="off" wire:model="authoriserPin"
               class="h-12 w-full rounded-xl border border-line bg-surface px-3 text-center text-lg tracking-[0.4em] dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
        <button type="button" wire:click="{{ $action }}" wire:loading.attr="disabled" wire:target="{{ $action }}" x-bind:disabled="typeof online !== 'undefined' && ! online"
                class="h-12 w-full rounded-xl bg-warning px-4 text-base font-semibold text-white transition hover:opacity-90 disabled:opacity-60">{{ __('Autorizar y registrar') }}</button>
        <p class="text-[11px] text-ink-muted dark:text-slate-400">{{ __('Quien atiende sigue identificado; la excepción queda registrada a nombre de quien autoriza.') }}</p>
    </div>
</div>
