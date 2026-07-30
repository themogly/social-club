@props(['card'])

@php
    $delta = $card['delta'] ?? null;
    $ring = $card['ring'] ?? null;
    $spark = $card['spark'] ?? null;
    $href = $card['href'] ?? '#';
    $isLink = $href !== '#' && $href !== null;
    $tag = $isLink ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if ($isLink) href="{{ $href }}" @endif
    @class(['csc-card', 'csc-card-link' => $isLink])
    @if ($card['flag'] ?? false) data-flag="true" @endif
>
    <div class="csc-card-head">
        <span class="csc-card-icon"><x-filament::icon :icon="$card['icon']" class="csc-ico" /></span>
        <span class="csc-card-label">{{ $card['label'] }}</span>

        @if ($delta)
            <span @class(['csc-delta', 'csc-delta-'.$delta['tone']])>
                {{-- Direction is carried by the arrow shape AND the number — never colour alone. --}}
                @if ($delta['dir'] === 'up')
                    <svg class="csc-delta-ico" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 10l4-4 4 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                @elseif ($delta['dir'] === 'down')
                    <svg class="csc-delta-ico" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                @else
                    <svg class="csc-delta-ico" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 8h8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                @endif
                <span>{{ $delta['label'] }}</span>
            </span>
        @endif
    </div>

    <div class="csc-card-body">
        <div>
            <div class="csc-card-value">{{ $card['value'] }}</div>
            @if (! empty($card['sub']))
                <div class="csc-card-sub">{{ $card['sub'] }}</div>
            @endif
        </div>

        @if ($ring)
            <x-dashboard.ring :fraction="$ring['fraction']" :inside="$ring['inside']" :capacity="$ring['capacity']" />
        @endif
    </div>

    @if (! empty($spark))
        <div class="csc-card-spark"><x-dashboard.spark :series="$spark" /></div>
    @endif
</{{ $tag }}>
