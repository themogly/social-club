{{-- Prompt 259 — the member OWES: said the moment they are held, whatever the reason, whatever the check-in toggle or
     the debt threshold, with the way to collect it right here. Collecting is a cash top-up into this sede's wallet
     against the open till (collectDebt). Debt at another sede is named but paid there — wallets are per sede. --}}
@if ($owesCents > 0)
    <div data-member-owes class="mt-2 rounded-xl border border-error/30 bg-error/10 p-3 text-sm text-error">
        <p class="font-semibold">{{ __('Este socio debe :money', ['money' => $this->money($owesCents)]) }}</p>
        @if ($owesCents > $owesHereCents)
            <p class="mt-0.5 text-[11px]">{{ __(':money de ello en otras sedes.', ['money' => $this->money($owesCents - $owesHereCents)]) }}</p>
        @endif
        @if ($owesHereCents > 0)
            <p class="mt-1 text-[11px] text-ink-muted dark:text-slate-400">{{ __('Cóbralo antes de dispensar.') }}</p>
            <div class="mt-2 flex gap-2">
                <input type="text" inputmode="decimal" wire:model="debtCollectInput" autocomplete="off"
                       placeholder="{{ $this->money($owesHereCents) }}" aria-label="{{ __('Importe a cobrar (€)') }}"
                       class="h-11 min-w-0 flex-1 rounded-xl border border-line bg-surface px-3 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                <x-button type="button" size="md" wire:click="collectDebt" data-collect-debt>{{ __('Cobrar ahora') }}</x-button>
            </div>
        @endif
    </div>
@endif
