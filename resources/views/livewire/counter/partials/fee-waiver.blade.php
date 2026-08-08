{{-- Forgo the outstanding fee — a recorded decision (prompt 219).

     The owner: *"need an option to waive the fee — often they waive it if they are medical, or if they have a
     membership at another club."* Before this, the only ways out of an outstanding fee were collecting it or
     a manager quietly not chasing it — so a club that routinely waives showed members permanently "owing"
     money it had decided not to take, and the door nagged about it for ever.

     **Secondary to collecting, deliberately.** Collecting is the norm and waiving is the exception, so this is
     a quiet link that opens a small form, never a button of equal weight beside "Cobrar cuota". One partial,
     rendered by all three hosts (Socios, the door, the dispensary) — the logic is in
     `CollectsMembershipFees` and this is the only markup.

     The reasons are STRUCTURED because the two common ones are data the system already holds: *Terapéutico*
     is offered when the member's own flag is set, *Socio en otra sede* when they hold an ACTIVE membership
     elsewhere in the club. A free-text box alone produces "ok" and "si". --}}
@can('membership.fee.waive')
    @php($waiveOptions = $this->waiveReasonOptions())
    <div class="mt-2" data-fee-waiver>
        <button
            type="button"
            wire:click="toggleWaive"
            data-fee-waive-toggle
            aria-expanded="{{ $waiveOpen ? 'true' : 'false' }}"
            class="inline-flex min-h-11 items-center rounded-lg px-3 text-xs font-medium text-ink-muted underline underline-offset-4 transition hover:text-ink dark:text-slate-400 dark:hover:text-slate-200"
        >{{ $waiveOpen ? __('Cancelar la condonación') : __('Condonar la cuota') }}</button>

        @if ($waiveOpen)
            <div data-fee-waive-form class="mt-2 rounded-xl border border-warning/40 bg-warning/5 p-3">
                <p class="text-xs font-medium text-warning">{{ __('El club renuncia a cobrar esta cuota. Queda registrado con tu nombre.') }}</p>

                <div role="radiogroup" aria-label="{{ __('Motivo') }}" class="mt-2 flex flex-col gap-1">
                    @foreach ($waiveOptions as $option)
                        <label class="flex min-h-11 cursor-pointer items-center gap-2 text-sm">
                            {{-- `@checked` as well as `wire:model`: the model binds on the next round trip, so
                                 without it the record-backed default `toggleWaive()` pre-selected is invisible
                                 on the first paint — the operator sees no reason chosen and picks one that was
                                 already right. --}}
                            <input type="radio" wire:model.live="waiveReason" value="{{ $option['value'] }}"
                                   data-waive-reason="{{ $option['value'] }}"
                                   @checked($waiveReason === $option['value'])
                                   class="h-5 w-5 shrink-0 border-line text-brand focus:ring-brand">
                            <span>{{ $option['label'] }}</span>
                        </label>
                    @endforeach
                </div>

                @if ($waiveReason === 'OTHER')
                    <input type="text" wire:model="waiveReasonText" data-waive-reason-text
                           placeholder="{{ __('Motivo de la condonación') }}" autocomplete="off"
                           class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                @endif

                <p class="mt-2 text-[11px] text-ink-muted dark:text-slate-400">
                    {{ __('Deja el importe vacío para condonar todo lo pendiente. No mueve caja ni monedero.') }}
                </p>

                <button type="button" wire:click="waiveFee" data-fee-waive-submit
                        class="mt-2 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-warning px-4 text-sm font-semibold text-white transition hover:opacity-90">
                    {{ __('Condonar cuota') }}
                </button>
            </div>
        @endif
    </div>
@endcan
