{{--
    What the commit DID, beside the control that did it (prompt 202).

    The confirmation said "Pedido registrado." and stopped, which tells the operator nothing the emptied
    basket had not already told them — while the one number a cash bar needs, the CHANGE DUE, had been zeroed
    by the cart reset before they could read it.

    Change first and largest: it is the only figure here that someone is waiting on. The charge and its split
    follow because they are what an operator is asked to confirm out loud; the reference last, when there is
    one. Every figure comes from App\Support\SettledOutcome — the settled row, not the live cart.

    Rendered INSIDE the flash's own live region by the caller, so it is announced as one message rather than
    two (prompt 199's rule: exactly one live region per outcome).
--}}
@php($outcome = $settled ?? [])

@if ($outcome !== [])
    <div data-settled-outcome class="mt-2 border-t border-current/20 pt-2">
        @if (($outcome['change_cents'] ?? 0) > 0)
            <p class="flex items-baseline justify-between gap-3">
                <span class="text-xs font-medium uppercase tracking-wide opacity-80">{{ __('Cambio') }}</span>
                <span data-outcome-change class="text-2xl font-bold tabular-nums">{{ \App\Support\Money::fromCents($outcome['change_cents'])->formatted() }}</span>
            </p>
        @endif

        <p class="mt-1 flex items-baseline justify-between gap-3 text-xs">
            <span class="opacity-80">{{ __('Cobrado') }}</span>
            <span data-outcome-total class="font-semibold tabular-nums">{{ \App\Support\Money::fromCents($outcome['total_cents'])->formatted() }}</span>
        </p>

        {{-- The split is shown only when it IS a split — repeating the total as "cash" adds nothing. --}}
        @if (($outcome['cash_cents'] ?? 0) > 0 && ($outcome['wallet_cents'] ?? 0) > 0)
            <p data-outcome-split class="mt-0.5 text-xs opacity-80">
                {{ __('Efectivo') }} {{ \App\Support\Money::fromCents($outcome['cash_cents'])->formatted() }}
                · {{ __('Monedero') }} {{ \App\Support\Money::fromCents($outcome['wallet_cents'])->formatted() }}
            </p>
        @endif

        @if (! empty($outcome['reference']))
            <p data-outcome-reference class="mt-0.5 text-xs opacity-80">{{ __('Referencia') }}: {{ $outcome['reference'] }}</p>
        @endif
    </div>
@endif
