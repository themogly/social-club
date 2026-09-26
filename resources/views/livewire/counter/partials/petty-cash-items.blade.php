{{-- Prompt 265 — what each petty-cash expense was FOR, under the "Caja chica" total: category, note, amount, who
     recorded it (the PIN operator) and when. One source (`TillSummary::breakdown()['petty_cash_items']`), shown on
     the till during the shift and on the arqueo after closing — never on the blind count. --}}
@if (! empty($items))
    <ul data-petty-cash-items class="mt-1 space-y-1 rounded-lg bg-surface-alt px-3 py-2 text-xs dark:bg-slate-800">
        @foreach ($items as $item)
            <li class="flex items-start justify-between gap-3">
                <span class="min-w-0">
                    <span class="font-medium text-ink dark:text-slate-100">{{ $item['note'] ?: __('Sin nota') }}</span>
                    <span class="block text-ink-muted dark:text-slate-400">{{ $item['category'] }} · {{ $item['recorded_by'] }} · {{ $item['at'] }}</span>
                </span>
                <span class="shrink-0 font-medium tabular-nums">{{ $this->money($item['amount_cents']) }}</span>
            </li>
        @endforeach
    </ul>
@endif
