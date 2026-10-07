{{-- Prompt 364/365 — one *Existencias* row (the main list and the «Agotados» group share it). --}}
<button type="button" wire:click="openBatch('{{ $row['id'] }}')" wire:key="stock-{{ $row['id'] }}" data-stock-row="{{ $row['id'] }}"
        @class(['mb-2 flex w-full min-h-11 flex-col gap-1 rounded-xl border px-3 py-2 text-left transition sm:flex-row sm:items-center sm:justify-between sm:gap-4',
            'border-brand bg-brand-tint/50 dark:bg-slate-800' => $open !== null && $open['id'] === $row['id'],
            'border-line bg-surface hover:border-brand hover:bg-brand-tint/40 dark:border-slate-700 dark:bg-slate-950 dark:hover:bg-slate-800' => $open === null || $open['id'] !== $row['id']])>
    <span class="min-w-0">
        <span class="block font-semibold text-ink dark:text-slate-100">{{ $row['name'] }}</span>
        <span class="block truncate text-xs text-ink-muted dark:text-slate-400">{{ $row['subtitle'] }}</span>
        @if ($row['last_count'])
            <span class="block text-xs text-ink-muted dark:text-slate-400" data-stock-last-count>{{ $row['last_count'] }}</span>
        @endif
    </span>
    <span class="flex shrink-0 flex-wrap items-center gap-x-4 gap-y-1 text-sm sm:justify-end">
        <span class="tabular-nums"><span class="text-ink-muted dark:text-slate-400">{{ __('En el bote') }}</span> <strong class="text-ink dark:text-slate-100">{{ $row['jar_text'] }}</strong></span>
        @if ($row['reserve_cg'] > 0)
            <span class="tabular-nums" data-stock-reserve><span class="text-ink-muted dark:text-slate-400">{{ __('Reserva') }}</span> <strong class="text-ink dark:text-slate-100">{{ $row['reserve_text'] }}</strong></span>
        @endif
        <span class="tabular-nums text-ink-muted dark:text-slate-400">{{ $row['price_text'] }}</span>
        @if ($row['chip'])
            <span data-stock-chip @class(['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold',
                'bg-brand-tint text-brand dark:bg-slate-800 dark:text-slate-200' => $row['reserve_cg'] > 0 || ! $row['jar_empty'],
                'bg-surface-alt text-ink-muted dark:bg-slate-800 dark:text-slate-400' => $row['empty']])>{{ $row['chip'] }}</span>
        @endif
    </span>
</button>
