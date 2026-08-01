@php
    use App\Support\Weight;
    $rows = $recall->rows();
@endphp

<div class="fi-ta overflow-x-auto text-sm">
    @if (empty($rows))
        <p class="py-6 text-center text-gray-500 dark:text-gray-400">{{ __('Nadie recibió producto de este lote') }}</p>
    @else
        <table class="w-full text-left">
            <thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                <tr>
                    <th class="py-2 pr-3">{{ __('Socio') }}</th>
                    <th class="py-2 pr-3">{{ __('Contacto') }}</th>
                    <th class="py-2 pr-3 text-right">{{ __('Gramos') }}</th>
                    <th class="py-2 pr-3">{{ __('Primera') }}</th>
                    <th class="py-2 pr-3">{{ __('Última') }}</th>
                    <th class="py-2">{{ __('Estado') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($rows as $row)
                    <tr>
                        <td class="py-2 pr-3">
                            <span class="font-medium text-gray-950 dark:text-white">{{ $row['socio'] }}</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $row['member_no'] }}</span>
                        </td>
                        <td class="py-2 pr-3 text-gray-600 dark:text-gray-300">{{ $row['contacto'] }}</td>
                        <td class="py-2 pr-3 text-right tabular-nums">{{ Weight::fromCentigrams($row['gramos'])->formatted() }}</td>
                        <td class="py-2 pr-3 text-gray-600 dark:text-gray-300">{{ $row['primera'] ? \Illuminate\Support\Carbon::parse($row['primera'])->format('d/m/Y') : '—' }}</td>
                        <td class="py-2 pr-3 text-gray-600 dark:text-gray-300">{{ $row['ultima'] ? \Illuminate\Support\Carbon::parse($row['ultima'])->format('d/m/Y') : '—' }}</td>
                        <td class="py-2">
                            @if ($row['estado'] === __('Completada'))
                                <span class="text-gray-500 dark:text-gray-400">{{ $row['estado'] }}</span>
                            @else
                                <span class="font-medium text-warning-600 dark:text-warning-400">{{ $row['estado'] }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
