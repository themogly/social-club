@props([
    'variant' => 'primary',   // primary | secondary | danger | danger-soft | warning
    'size' => 'md',           // sm | md | lg | xl  (height/padding — counter screens use lg/xl)
    'href' => null,           // when set, renders an <a> instead of a <button>
])

{{--
    The ONE call-to-action button. Colours come only from the brand palette; every
    variant carries a visible focus ring (a11y) and dark-mode styling. Layout classes
    (w-full, flex-1, mt-*, etc.) and behaviour (wire:click, type, @click, x-bind:disabled,
    wire:loading) pass straight through via $attributes — never bake them in here.
    Extracted in prompt 36 to end the hand-rolled per-screen drift.
--}}
@php
    $base = 'inline-flex items-center justify-center rounded-xl font-semibold transition focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:cursor-not-allowed disabled:opacity-60';

    $variants = [
        'primary' => 'bg-brand text-white hover:bg-brand-dark',
        'secondary' => 'border border-line bg-surface-alt text-ink hover:bg-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700',
        'danger' => 'bg-error text-white hover:opacity-90',
        'danger-soft' => 'border border-error/40 bg-error/10 text-error hover:bg-error/20',
        'warning' => 'bg-warning text-white hover:opacity-90',
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
@else
    <button {{ $attributes->merge(['type' => 'button'])->class($classes) }}>{{ $slot }}</button>
@endif
