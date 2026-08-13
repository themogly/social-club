{{-- Forgo the outstanding fee — a recorded decision (prompt 219).

     The owner: *"need an option to waive the fee — often they waive it if they are medical, or if they have a
     membership at another club."* Before this, the only ways out of an outstanding fee were collecting it or
     a manager quietly not chasing it — so a club that routinely waives showed members permanently "owing"
     money it had decided not to take, and the door nagged about it for ever.

     **A PEER BUTTON beside "Cobrar cuota" — prompt 234 REVERSES 219's hierarchy, on the owner's instruction.**

     219 wrote it as *"a quiet link, never a button of equal weight"*, on the theory that collecting is the
     norm and waiving the exception. The owner, from live use: *"make waive a fee a button next to collect fee
     — it is more obvious and frees up some space."* His club waives routinely (therapeutic members, members
     of another sede — the two reasons 219 itself made structured), so the quiet link buried a daily action
     and spent a whole row doing it.

     Collect keeps primary weight; waive is a full-height secondary beside it. **Nothing behind it changed**:
     the `membership.fee.waive` gate, 229's structured reasons and their preselection, the required reason,
     the audit row and the WAIVED payment are all exactly as they were. A louder button, the same rules.

     One partial, rendered by all three hosts (Socios, the door, the dispensary) — the logic is in
     `CollectsMembershipFees` and this is the only markup.

     The reasons are STRUCTURED because the two common ones are data the system already holds: *Terapéutico*
     is offered when the member's own flag is set, *Socio en otra sede* when they hold an ACTIVE membership
     elsewhere in the club. A free-text box alone produces "ok" and "si". --}}
{{-- `$part` (prompt 234): `trigger` is the button that sits BESIDE Cobrar cuota, `form` is the reason panel
     it opens, which needs the full width. Default `both` keeps every existing caller working unchanged. --}}
@php($part = $part ?? 'both')

@can('membership.fee.waive')
    @php($waiveOptions = $this->waiveReasonOptions())
    <div @class(['contents' => $part !== 'both', 'mt-2' => $part === 'both']) data-fee-waiver>
        @if ($part !== 'form')
        <button
            type="button"
            wire:click="toggleWaive"
            data-fee-waive-toggle
            aria-expanded="{{ $waiveOpen ? 'true' : 'false' }}"
            class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-line bg-surface-alt px-4 text-sm font-semibold text-ink transition hover:bg-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700"
        >{{ $waiveOpen ? __('Cancelar la condonación') : __('Condonar') }}</button>
        @endif

        @if ($waiveOpen && $part !== 'trigger')
            <div data-fee-waive-form class="mt-2 rounded-xl border border-warning/40 bg-warning/5 p-3">
                <p class="text-xs font-medium text-warning">{{ __('El club renuncia a cobrar esta cuota. Queda registrado con tu nombre.') }}</p>

                {{-- A `name`, which these radios did not have (prompt 229). Radios are mutually exclusive only
                     within a name group, so each of these was a group of ONE: clicking a second one checked it
                     WITHOUT unchecking the first, and both stayed lit until Livewire's round trip morphed the
                     attribute back. On localhost that window is ~100ms and invisible; on a counter tablet on
                     wifi it is long, and if the update is dropped it never ends — which is the state the owner
                     photographed. The "toggle" he asked for IS native radio behaviour; the markup had opted
                     out of it.

                     Scoped to the component instance, so a future page rendering two hosts cannot put two
                     waivers in one group. --}}
                @php($waiveGroup = 'waive-reason-'.$this->getId())

                <div role="radiogroup" aria-label="{{ __('Motivo') }}" class="mt-2 flex flex-col gap-1">
                    @foreach ($waiveOptions as $option)
                        {{-- Keyed: this list legitimately CHANGES between renders (it is computed from the
                             member's record), and morphing an unkeyed list that has changed is how a label
                             ends up paired with another option's checked state. --}}
                        <label wire:key="{{ $waiveGroup }}-{{ $option['value'] }}" class="flex min-h-11 cursor-pointer items-center gap-2 text-sm">
                            {{-- `@checked` as well as `wire:model`: the model binds on the next round trip, so
                                 without it the record-backed default `toggleWaive()` pre-selected is invisible
                                 on the first paint — the operator sees no reason chosen and picks one that was
                                 already right. --}}
                            <input type="radio" name="{{ $waiveGroup }}" wire:model.live="waiveReason" value="{{ $option['value'] }}"
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
