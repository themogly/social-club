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
        {{-- One action, at the counter's 44x44 floor. --}}
        <a
            href="{{ $actionHref }}"
            wire:navigate
            data-blocker-action
            class="mt-6 inline-flex min-h-[2.75rem] items-center justify-center rounded-xl bg-brand px-6 text-sm font-semibold text-white transition hover:bg-brand-dark"
        >{{ $actionLabel }}</a>
    @endif
</div>
