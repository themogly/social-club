@props(['headers' => [], 'rows' => [], 'numeric' => [], 'empty' => null, 'label' => null])

@if (empty($rows))
    <x-dashboard.empty :message="$empty ?? __('Sin datos en este período')" />
@else
    {{-- tabindex + role=region: the wrapper scrolls horizontally, and a scrollable box that nothing inside
         can take focus on is unreachable by keyboard (a11y audit). $label names it — several of these can be
         on one page, and identically-named landmarks are no more distinguishable than unnamed ones. --}}
    <div class="csc-table-wrap" tabindex="0" role="region" aria-label="{{ $label ?? __('Tabla desplazable') }}">
        <table class="csc-table">
            <thead>
                <tr>
                    @foreach ($headers as $i => $header)
                        <th @class(['csc-num' => in_array($i, $numeric)])>{{ $header }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach ($row as $i => $cell)
                            <td @class(['csc-num' => in_array($i, $numeric)])>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
