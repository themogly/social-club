@props([
    'heading',
    'body',
    'actionLabel' => null,
    'actionHref' => null,
    'icon' => '📍',
])

{{--
    THE counter's one blocking pattern (prompt 175): one heading naming what is missing, one sentence of
    consequence, one button that fixes it. Used for every precondition on every counter screen, and shown
    ONE AT A TIME in dependency order — sede → operator → till → member.

    It replaces four styles that appeared simultaneously on the dispensary: an amber strip, a red card with
    a destructive-styled button, a grey empty state and grey helper text restating one of the others.

    Colour has one meaning (the audit's fourth standard): a blocked state is neutral/amber, red is
    DESTRUCTIVE, navigation is neither. `Ir a la caja` was a dark-red button on a screen that was already
    blocked, which reads as an error the operator caused. It is navigation, so it is the brand button.

    The one action is usually a LINK somewhere else, because that is where most fixes live — the topbar sede
    switcher, the Caja screen. The member step is the exception: the thing that fixes it is the member search
    itself, so the slot takes the control inline and the state stays resolvable rather than a dead end. Either
    way it is still one action, and the state is still the whole screen.

    Not a gate — every precondition is still enforced server-side. This is what the operator sees.
--}}
<div
    data-counter-blocker
    data-blocker="{{ $attributes->get('data-blocker') }}"
    {{ $attributes->except('data-blocker')->class(['flex min-h-[60vh] flex-col items-center justify-center px-6 text-center']) }}
>
    <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-surface-alt text-3xl dark:bg-slate-800" aria-hidden="true">{{ $icon }}</div>

    <h2 class="mt-5 text-xl font-semibold">{{ $heading }}</h2>
    <p class="mt-2 max-w-sm text-sm text-ink-muted dark:text-slate-400">{{ $body }}</p>

    @if ($actionLabel !== null && $actionHref !== null)
        {{-- One action, at the counter's 44x44 floor. Navigation is the brand button, never the destructive
             one: `Ir a la caja` was bg-error on an already-blocked screen, which reads as an error caused. --}}
        <a
            href="{{ $actionHref }}"
            wire:navigate
            data-blocker-action
            class="mt-6 inline-flex min-h-[2.75rem] items-center justify-center rounded-xl bg-brand px-6 text-sm font-semibold text-white transition hover:bg-brand-dark"
        >{{ $actionLabel }}</a>
    @endif

    @if (! $slot->isEmpty())
        {{-- The fix, inline. Left-aligned inside a centred column: labelled fields read as fields, not as
             more centred prose. --}}
        <div data-blocker-action class="mt-6 w-full max-w-sm text-left">{{ $slot }}</div>
    @endif
</div>
