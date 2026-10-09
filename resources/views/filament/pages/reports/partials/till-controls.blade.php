{{-- Prompt 366 — «Solo con diferencia»: the closes beyond the tolerance, where the owner looks first. In the URL, so the
     dashboard and the morning summary link straight to it. --}}
<div class="csc-section" data-till-controls>
    <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 text-sm font-medium">
        <input type="checkbox" wire:model.live="diferencia" data-only-variance
               class="h-5 w-5 rounded border-gray-300 text-primary-600 focus:ring-primary-600 dark:border-white/20 dark:bg-white/5">
        <span>{{ __('Solo con diferencia') }}</span>
    </label>
    {{-- Prompt 380 — the closes that left a «Cada noche» box uncounted. --}}
    <label class="ml-4 inline-flex min-h-11 cursor-pointer items-center gap-2 text-sm font-medium">
        <input type="checkbox" wire:model.live="sin_contar" data-only-uncounted-boxes
               class="h-5 w-5 rounded border-gray-300 text-primary-600 focus:ring-primary-600 dark:border-white/20 dark:bg-white/5">
        <span>{{ __('Botes sin contar') }}</span>
    </label>
</div>
