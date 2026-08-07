@props(['title' => null, 'href' => null, 'subtitle' => null, 'icon' => null, 'count' => null])

<section class="csc-section">
    <div class="csc-section-head">
        <div class="csc-section-heading">
            @if ($icon)
                <x-filament::icon :icon="$icon" class="csc-section-ico" />
            @endif
            @if ($title)
                <h2 class="csc-section-title">{{ $title }}</h2>
            @endif
            @if (! is_null($count))
                <span class="csc-count">{{ $count }}</span>
            @endif
        </div>

        @if ($href)
            <a href="{{ $href }}" class="csc-section-link">{{ __('Ver todo') }}</a>
        @endif
    </div>

    @if ($subtitle)
        <p class="csc-section-sub">{{ $subtitle }}</p>
    @endif

    <div class="csc-section-body">
        {{ $slot }}
    </div>
</section>
