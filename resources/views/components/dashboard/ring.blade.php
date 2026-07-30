@props(['fraction' => null, 'inside' => 0, 'capacity' => null])

@php
    $frac = $fraction !== null ? max(0.0, min(1.0, (float) $fraction)) : null;
    $r = 26;
    $circ = 2 * M_PI * $r;
    $offset = $frac !== null ? $circ * (1 - $frac) : $circ;
    $tone = $frac === null ? 'muted' : ($frac >= 1 ? 'error' : ($frac >= 0.85 ? 'warning' : 'ok'));
    $label = $capacity !== null && $frac !== null ? round($frac * 100).'%' : (string) $inside;
@endphp

<div @class(['csc-ring', 'csc-ring-'.$tone]) title="{{ $capacity !== null ? __(':inside de :capacity', ['inside' => $inside, 'capacity' => $capacity]) : $inside }}">
    <svg class="csc-ring-svg" viewBox="0 0 64 64" aria-hidden="true">
        <circle class="csc-ring-track" cx="32" cy="32" r="{{ $r }}" />
        @if ($frac !== null)
            <circle class="csc-ring-fill" cx="32" cy="32" r="{{ $r }}"
                stroke-dasharray="{{ round($circ, 2) }}"
                stroke-dashoffset="{{ round($offset, 2) }}"
                transform="rotate(-90 32 32)" />
        @endif
    </svg>
    <span class="csc-ring-label">{{ $label }}</span>
</div>
