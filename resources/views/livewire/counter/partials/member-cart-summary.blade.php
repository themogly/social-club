{{-- Prompt 176 — who is being served, and what they may still have, at the TOP OF THE CART.

     The audit's finding 3 was that the socio column occupied the first ~700px of the screen (a 194px
     identify panel plus a 403px member card, both full-width) before a single genetic appeared. The card
     is not deleted — it is SPLIT. Identity and the allowance come here, where they stay on screen beside
     the basket for the whole sale; the wallet, carencia, sanction and counter verdict stay in the cart's
     scrolling region, next to the payment apparatus they inform.

     The allowance is on the cart because Flowhub renders the remaining limit persistently in the cart's
     upper right and Treez puts Purchase Limits at the top of the cart: an operator must never leave the
     sale to find out whether the socio may have what they are asking for. `Restante hoy` is the headline
     because it is the figure that decides the next line, not the month-to-date total.

     Same resolver as everywhere else (`ResolveMemberEligibility` via $limits) — this is where an answer is
     SHOWN, never a second calculation. --}}
@if ($member)
    <section
        data-member-summary
        class="rounded-2xl border border-line bg-surface p-3 dark:border-slate-800 dark:bg-slate-900"
    >
        <div class="flex items-start gap-2.5">
            @if ($photoUrl)
                <img src="{{ $photoUrl }}" alt="" class="h-11 w-11 shrink-0 rounded-xl object-cover">
            @else
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-tint text-sm font-bold text-brand dark:bg-slate-800 dark:text-slate-200">
                    {{ mb_strtoupper(mb_substr($member->first_name, 0, 1).mb_substr($member->last_name, 0, 1)) }}
                </div>
            @endif

            <div class="min-w-0 flex-1">
                <h2 class="truncate text-sm font-bold leading-tight">{{ $member->fullName() }}</h2>
                <p class="mt-0.5 truncate text-xs text-ink-muted dark:text-slate-400">
                    {{ $member->member_no }}
                    <span class="rounded-full border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $statusColour }}">{{ $member->status->label() }}</span>
                </p>
            </div>

            {{-- Was 52x32 — under the counter's 44x44 floor, measured in a real browser after a rebuild. --}}
            <button
                type="button"
                wire:click="clearMember"
                aria-label="{{ __('Cerrar ficha del socio') }}"
                class="inline-flex h-11 min-w-[2.75rem] shrink-0 items-center justify-center rounded-lg px-2 text-sm text-ink-muted transition hover:bg-black/5 dark:text-slate-400 dark:hover:bg-white/5"
            >{{ __('Cerrar') }}</button>
        </div>

        {{-- The allowance. Colour AND numbers, never colour alone. --}}
        @if ($limits)
            @php
                $pct = $limits->monthlyPercent();
                $gaugeState = $limits->gaugeState();
                $gaugeBar = match ($gaugeState) { 'alert' => 'bg-error', 'warning' => 'bg-warning', default => 'bg-success' };
                $gaugeText = match ($gaugeState) { 'alert' => 'text-error', 'warning' => 'text-warning', default => 'text-success' };
            @endphp
            <div data-member-allowance class="mt-2.5 border-t border-line pt-2.5 dark:border-slate-800">
                <div class="flex items-baseline justify-between gap-2">
                    <span class="text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Restante hoy') }}</span>
                    <span class="text-base font-bold {{ $gaugeText }}">{{ $this->grams($limits->dailyRemainingCg()) }}</span>
                </div>
                <div class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                    <div class="h-full rounded-full {{ $gaugeBar }}" style="width: {{ min($pct, 100) }}%"></div>
                </div>
                <p class="mt-1 text-[11px] text-ink-muted dark:text-slate-400">
                    {{ __('Mes') }}: {{ $this->grams($limits->monthlyUsedCg) }} / {{ $this->grams($limits->monthlyLimitCg) }} · {{ $pct }}%
                </p>
            </div>
        @endif
    </section>
@endif
