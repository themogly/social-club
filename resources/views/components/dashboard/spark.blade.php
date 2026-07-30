@props(['series' => []])

@php
    $series = array_values(array_filter(
        array_map(fn ($v) => is_numeric($v) ? (float) $v : null, (array) $series),
        fn ($v) => $v !== null,
    ));
@endphp

@if (count($series) > 1)
    @php
        $max = max($series);
        $min = min($series);
        $range = max(0.0001, $max - $min);
        $n = count($series);
        $w = 120;
        $h = 32;
        $points = [];
        foreach ($series as $i => $v) {
            $x = ($i / ($n - 1)) * $w;
            $y = $h - (($v - $min) / $range) * ($h - 4) - 2;
            $points[] = round($x, 1).','.round($y, 1);
        }
        $line = implode(' ', $points);
        $area = '0,'.$h.' '.$line.' '.$w.','.$h;
    @endphp
    <svg class="csc-spark" viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="none" aria-hidden="true">
        <polygon class="csc-spark-area" points="{{ $area }}" />
        <polyline class="csc-spark-line" points="{{ $line }}" />
    </svg>
@endif
