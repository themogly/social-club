{{-- Prompt 300 — after a sale, ONE line in the basket column, the options behind it. Shared by the dispensary and the bar.

     The block it replaces held the receipt button, a full-width email button and an OPEN void form, and it stayed
     through the next member's whole visit (only a void cleared it). The three actions stay — a wrong sale caught at once
     is best voided right there, and some members ask for a receipt — but behind *Opciones*, and the void is a sheet
     with its reason, never a textarea sitting open on the main screen. The component clears the line with the next
     thing that happens (a line added, a member changed).

     @var string $summary      "Última: 15,00 € · 1,00 g · 14:02"
     @var string $receiptUrl   the unchanged receipt / ticket route
     @var string $receiptLabel @var string $receiptHeading
     @var bool   $emailable    the dispensary, and only when the member has an address
     @var bool   $canVoid
     @var string $voidHeading  @var string $voidReasonId --}}
<div data-last-sale class="flex min-h-11 items-center justify-between gap-2 rounded-xl border border-line bg-surface-alt px-3 py-1 dark:border-slate-700 dark:bg-slate-800/50">
    {{-- Wraps rather than truncates: in the portrait cart column (820×1180) a truncated line lost the grams and the time. --}}
    <p class="min-w-0 text-sm leading-tight text-ink dark:text-slate-100">
        <span aria-hidden="true" class="font-bold text-success">✓</span>
        {{-- Each part stays whole ("1,00 g" never splits); a wrap falls between parts. --}}
        <span class="font-semibold">{!! collect(explode(' · ', $summary))->map(fn (string $part): string => '<span class="whitespace-nowrap">'.e($part).'</span>')->implode(' · ') !!}</span>
    </p>

    <div class="relative shrink-0" x-data="{ menu: false }" @keydown.escape.window="menu = false">
        <button type="button" data-last-sale-options @click="menu = ! menu" :aria-expanded="menu" aria-haspopup="menu"
                class="inline-flex h-11 items-center gap-1 rounded-lg px-3 text-sm font-semibold text-ink-muted transition hover:bg-black/5 dark:text-slate-300 dark:hover:bg-white/5">
            {{ __('Opciones') }}
            <x-counter.icon name="chevron-down" class="h-4 w-4" />
        </button>

        {{-- Opens upwards: the line sits just above the commit button at the foot of the column. An item closes the menu
             and opens its sheet (below) by event. --}}
        <div x-show="menu" x-cloak @click.outside="menu = false" role="menu"
             class="absolute bottom-full right-0 z-30 mb-2 flex w-60 flex-col gap-1 rounded-xl border border-line bg-surface p-2 shadow-xl dark:border-slate-700 dark:bg-slate-900">
            <button type="button" role="menuitem" data-receipt-open @click="menu = false; $dispatch('counter-receipt-open')"
                    class="inline-flex h-11 items-center rounded-xl px-4 text-left text-sm font-semibold text-ink transition hover:bg-surface-alt dark:text-slate-100 dark:hover:bg-slate-800">{{ $receiptLabel }}</button>

            @if ($emailable)
                <button type="button" role="menuitem" data-last-sale-email wire:click="emailReceipt" @click="menu = false" wire:loading.attr="disabled" wire:target="emailReceipt"
                        class="inline-flex h-11 items-center rounded-xl px-4 text-left text-sm font-semibold text-ink transition hover:bg-surface-alt disabled:opacity-50 dark:text-slate-100 dark:hover:bg-slate-800">{{ __('Enviar por email') }}</button>
            @endif

            @if ($canVoid)
                <button type="button" role="menuitem" data-last-sale-void @click="menu = false; $dispatch('counter-sheet-open', { name: 'void' })"
                        class="inline-flex h-11 w-full items-center rounded-xl px-4 text-left text-sm font-semibold text-error transition hover:bg-error/10">{{ __('Anular…') }}</button>
            @endif
        </div>
    </div>
</div>

{{-- The sheets live HERE, in the column's flow where 252's receipt sheet always sat, and the menu items open them by
     event. Nested inside the popover they were trapped under the product list (measured in the browser). --}}
<x-counter.receipt-sheet :url="$receiptUrl" :label="$receiptLabel" :heading="$receiptHeading" :trigger="false" />
@if ($canVoid)
    <x-counter.sheet name="void" :heading="$voidHeading">
        <div x-data="{ reason: '' }">
            <label for="{{ $voidReasonId }}" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Motivo de la anulación (queda registrado)') }}</label>
            <textarea id="{{ $voidReasonId }}" wire:model="voidReason" x-model="reason" rows="3" required
                      class="mt-1 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm focus:border-error focus:outline-none focus:ring-2 focus:ring-error/30 dark:border-slate-700 dark:bg-slate-950"></textarea>
            <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">{{ __('Se revertirán stock y monedero.') }}</p>
            <div class="mt-3 grid grid-cols-2 gap-2">
                <x-button type="button" variant="secondary" size="md" @click="close()">{{ __('Cancelar') }}</x-button>
                <x-button type="button" variant="danger" size="md" data-last-sale-void-confirm wire:click="voidLast" x-bind:disabled="reason.trim() === ''">{{ __('Anular') }}</x-button>
            </div>
        </div>
    </x-counter.sheet>
@endif
