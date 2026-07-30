@props(['headers' => [], 'rows' => [], 'numeric' => [], 'empty' => null])

@if (empty($rows))
    <x-dashboard.empty :message="$empty ?? __('Sin datos en este período')" />
@else
    <div class="csc-table-wrap">
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
