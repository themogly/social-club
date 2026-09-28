{{-- Prompt 282 / 298 — one manual-lote chip at the dispensary, under the strain's heading: so the strain is not repeated and the
     lote number never shows at the counter. The club's name for the batch, or "#3 · 12 sep"; the full display name as
     the title; then what is left. Keeps the 44px floor. --}}
@props(['batch', 'selected' => false, 'quantity' => '', 'fefo' => false])

<button type="button" wire:click="selectBatch('{{ $batch->id }}')" aria-pressed="{{ $selected ? 'true' : 'false' }}"
        title="{{ $batch->displayName() }}" data-batch-chip="{{ $batch->id }}" @class([
    'inline-flex min-h-11 max-w-full items-center gap-1 rounded-lg border px-3 py-1.5 text-sm transition',
    'border-brand bg-brand text-white' => $selected,
    'border-line bg-surface text-ink hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100' => ! $selected,
])>
    <span class="min-w-0 max-w-[14rem] truncate font-medium">{{ $batch->displaySubtitle(short: true) }}</span>
    <span class="shrink-0 opacity-70">· {{ $quantity }}</span>
    @if ($fefo)<span class="ml-1 shrink-0 rounded-full bg-white/20 px-1.5 py-0.5 text-[10px] font-semibold uppercase">FEFO</span>@endif
</button>
