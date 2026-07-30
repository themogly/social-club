@props([
    'table',            // App\ViewModels\Reports\ReportTable
    'sortKey' => null,  // currently-sorted column key (primary table only)
    'sortDir' => 'desc',
    'interactive' => false, // whether headers are clickable (wire:click="sortBy")
])

@php
    /** @var \App\ViewModels\Reports\ReportTable $table */
    $columns = $table->columns;
@endphp

@if (! $table->hasRows())
    <div class="csc-empty">
        <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedInbox" class="csc-empty-ico" />
        <p class="csc-empty-msg">{{ $table->empty ?? __('Sin datos en este período') }}</p>
        @if ($table->emptyHint)
            <p class="csc-empty-hint">{{ $table->emptyHint }}</p>
        @endif
    </div>
@else
    <div class="csc-table-wrap">
        <table class="csc-table csc-rep-table">
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        @php $isSorted = $interactive && $sortKey === $column->key; @endphp
                        <th
                            @class(['csc-num' => $column->numeric()])
                            @if ($isSorted) aria-sort="{{ $sortDir === 'asc' ? 'ascending' : 'descending' }}" @endif
                        >
                            @if ($interactive && $table->sortable && $column->sortable)
                                <button type="button" class="csc-sort {{ $isSorted ? 'csc-sort-on' : '' }}" wire:click="sortBy('{{ $column->key }}')">
                                    <span>{{ $column->label }}</span>
                                    <span class="csc-sort-caret" aria-hidden="true">{{ $isSorted ? ($sortDir === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                </button>
                            @else
                                {{ $column->label }}
                            @endif
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($table->rows as $row)
                    <tr>
                        @foreach ($columns as $column)
                            <td @class(['csc-num' => $column->numeric()])>{{ $column->display($row[$column->key] ?? null) }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
            @if ($table->hasTotals())
                <tfoot>
                    <tr class="csc-total-row">
                        @foreach ($columns as $i => $column)
                            <td @class(['csc-num' => $column->numeric()])>
                                @if ($i === 0)
                                    {{ __('Total') }}
                                @elseif (array_key_exists($column->key, $table->totals))
                                    {{ $column->display($table->totals[$column->key]) }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
    @if ($table->note)
        <p class="csc-rep-note">{{ $table->note }}</p>
    @endif
@endif
