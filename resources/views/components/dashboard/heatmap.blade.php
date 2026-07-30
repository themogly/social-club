@props(['footfall' => ['matrix' => [], 'max' => 0]])

@php
    $matrix = $footfall['matrix'] ?? [];
    $max = max(1, (int) ($footfall['max'] ?? 0));
    // Footfall is keyed 0=Sun..6=Sat; present it Monday-first.
    $order = [1, 2, 3, 4, 5, 6, 0];
    $days = [__('Lun'), __('Mar'), __('Mié'), __('Jue'), __('Vie'), __('Sáb'), __('Dom')];
@endphp

@if (($footfall['max'] ?? 0) === 0)
    <x-dashboard.empty :message="__('Sin entradas registradas en el período')" :icon="\Filament\Support\Icons\Heroicon::OutlinedClock" />
@else
    <div class="csc-heat-wrap">
        <div class="csc-heat">
            <div class="csc-heat-corner"></div>
            @for ($h = 0; $h < 24; $h++)
                <div class="csc-heat-hour">{{ $h % 3 === 0 ? $h : '' }}</div>
            @endfor

            @foreach ($order as $index => $weekday)
                <div class="csc-heat-day">{{ $days[$index] }}</div>
                @for ($h = 0; $h < 24; $h++)
                    @php
                        $value = (int) ($matrix[$weekday][$h] ?? 0);
                        $opacity = $value > 0 ? round(0.15 + 0.85 * ($value / $max), 3) : 0;
                    @endphp
                    <div class="csc-heat-cell" style="--op: {{ $opacity }}" title="{{ $days[$index] }} · {{ $h }}:00 · {{ $value }}"></div>
                @endfor
            @endforeach
        </div>
    </div>
@endif
