@props([
    'variant' => 'primary',   // primary | secondary | danger | danger-soft | warning
    'size' => 'md',           // sm | md | lg | xl  (height/padding — counter screens use lg/xl)
    'href' => null,           // when set, renders an <a> instead of a <button>
    'as' => null,             // 'label' — a button-looking <label> wrapping a visually hidden file input (prompt 272)
])

{{--
    The ONE call-to-action button. Colours come only from the brand palette; every
    variant carries a visible focus ring (a11y) and dark-mode styling. Layout classes
    (w-full, flex-1, mt-*, etc.) and behaviour (wire:click, type, @click, x-bind:disabled,
    wire:loading) pass straight through via $attributes — never bake them in here.
    Extracted in prompt 36 to end the hand-rolled per-screen drift.
--}}
@php
    // Focus ring (prompt 272): the old `focus:ring-brand/40` with no offset measured 1.5–2.9:1 — on a blue fill
    // the focused commit looked unfocused. Full-strength brand ring, offset by the page colour of each scheme
    // so it reads against the fill AND the surface (WCAG 2.4.7 / 1.4.11), and only for keyboard focus.
    $base = 'inline-flex items-center justify-center rounded-xl font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2 focus-visible:ring-offset-surface dark:focus-visible:ring-offset-slate-950 disabled:cursor-not-allowed disabled:opacity-60';

    $variants = [
        'primary' => 'bg-brand text-white hover:bg-brand-dark',
        'secondary' => 'border border-line bg-surface-alt text-ink hover:bg-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700',
        // Solid danger/warning paint the FILL tokens (prompt 272), never the text tokens: in dark the text
        // values take white at 2.77:1 / 3.19:1. `brightness-90` darkens on hover, which only raises contrast
        // (the old `opacity-90` faded the white label with the fill).
        'danger' => 'bg-error-fill text-white hover:brightness-90',
        'danger-soft' => 'border border-error/40 bg-error/10 text-error hover:bg-error/20',
        'warning' => 'bg-warning-fill text-white hover:brightness-90',
        'outline' => 'border border-brand text-brand hover:bg-brand-tint dark:text-slate-100 dark:hover:bg-slate-800',
    ];

    $sizes = [
        'sm' => 'h-10 px-4 text-sm',
        'md' => 'h-12 px-6 text-base',
        'lg' => 'h-14 px-6 text-base',
        'xl' => 'h-16 px-6 text-lg font-bold',
    ];

    $classes = $base.' '.($variants[$variant] ?? $variants['primary']).' '.($sizes[$size] ?? $sizes['md']);
@endphp

@if ($href !== null)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@elseif ($as === 'label')
    {{-- A label is not focusable itself; the hidden input inside it is, so the ring follows focus-within. --}}
    <label {{ $attributes->class([$classes, 'cursor-pointer focus-within:ring-2 focus-within:ring-brand focus-within:ring-offset-2 focus-within:ring-offset-surface dark:focus-within:ring-offset-slate-950']) }}>{{ $slot }}</label>
@else
    <button {{ $attributes->merge(['type' => 'button'])->class($classes) }}>{{ $slot }}</button>
@endif
