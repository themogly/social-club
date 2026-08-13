{{-- Inline membership-fee collection (prompt 127). Appears wherever the unpaid-fee verdict is rendered — the
     door and the dispensary POS member card — for an operator who holds membership.fee.collect. It drives the
     SAME shared concern (CollectsMembershipFees → RecordFeePayment, the single writer): a CASH fee needs the
     open drawer, a WALLET fee does not, and collecting it is what clears the unpaid_fee verdict on re-render.

     Contract: $canCollectFee (bool), $feeOwedCents (int|null), $openTillPresent (bool). The host resolves the
     already-held member itself and exposes collectMemberFee(). --}}
@if ($canCollectFee && $feeOwedCents)
    <div class="mt-2 rounded-xl border border-warning/40 bg-warning/5 p-3">
        <p class="text-sm font-medium">{{ __('Cobrar cuota pendiente') }} · <span class="font-semibold">{{ $this->money($feeOwedCents) }}</span></p>
        <form wire:submit="collectMemberFee" class="mt-2 space-y-2">
            <input
                type="text" inputmode="decimal" wire:model="feeAmount" autocomplete="off"
                aria-label="{{ __('Importe (€)') }}"
                placeholder="{{ number_format($feeOwedCents / 100, 2, ',', '') }}"
                class="h-11 w-full rounded-lg border border-line bg-surface px-3 text-sm text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
            >
            <div class="grid grid-cols-2 gap-2">
                <button type="button" wire:click="$set('feeMethod', 'CASH')" @class(['h-11 rounded-lg border text-sm font-semibold', 'border-brand bg-brand text-white' => $feeMethod === 'CASH', 'border-line text-ink dark:border-slate-700 dark:text-slate-100' => $feeMethod !== 'CASH'])>{{ __('Efectivo') }}</button>
                <button type="button" wire:click="$set('feeMethod', 'WALLET')" @class(['h-11 rounded-lg border text-sm font-semibold', 'border-brand bg-brand text-white' => $feeMethod === 'WALLET', 'border-line text-ink dark:border-slate-700 dark:text-slate-100' => $feeMethod !== 'WALLET'])>{{ __('Monedero') }}</button>
            </div>
            {{-- SIDE BY SIDE (prompt 234), collect keeping primary weight. The waiver's own control lives in
                 its partial so all three hosts get one markup; it is placed in this row rather than under it,
                 which is the whole of the owner's ask — *"a button next to collect fee… frees up some space."* --}}
            <div class="grid grid-cols-2 gap-2">
                <x-button type="submit" size="md" class="w-full" wire:loading.attr="disabled" wire:target="collectMemberFee">{{ __('Cobrar cuota') }}</x-button>

                {{-- Prompt 219 — the waiver follows the fee wherever the fee is collected, for the same reason
                     127 put the collect action here: the fix belongs beside the thing it fixes. It needs no
                     open till. --}}
                @include('livewire.counter.partials.fee-waiver', ['part' => 'trigger'])
            </div>
        </form>

        {{-- …and its reason form below, full width: a radio group squeezed into half a row is not a choice
             anybody makes correctly. --}}
        @include('livewire.counter.partials.fee-waiver', ['part' => 'form'])
        @unless ($openTillPresent)
            <p class="mt-1.5 text-[11px] text-warning">{{ __('Sin caja abierta solo se admite el cobro con monedero.') }}</p>
        @endunless
    </div>
@endif
