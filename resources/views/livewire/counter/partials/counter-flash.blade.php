{{--
    The counter's ONE flash block (prompts 192/193). Extracted so it can render in two places without two
    copies of the markup — the host decides WHERE, never WHAT.

    It has to appear in both because the two cases exclude each other:
      · a blocking state replaces the work (no till, no sede), and the cart column with it — so the reason
        has to be at the top of the page or it has nowhere to go. That is why it lived there originally.
      · otherwise the operator is looking at the cart column, and the answer to "I pressed Charge" belongs
        beside Charge — measured at ~650px away before this, in an 820px viewport.

    Callers pass `$anchor` purely as a test hook so each position is addressable.
--}}
@if ($flashMessage)
    <div
        wire:key="flash"
        {{ $anchor ?? '' }}
        role="{{ $flashType === 'error' ? 'alert' : 'status' }}"
        aria-live="{{ $flashType === 'error' ? 'assertive' : 'polite' }}"
        @class([
            'mb-4 flex items-center justify-between gap-3 rounded-xl border px-4 py-3 text-sm font-medium',
            'border-success/30 bg-success/10 text-success' => $flashType === 'success',
            'border-warning/30 bg-warning/10 text-warning' => $flashType === 'warning',
            'border-error/30 bg-error/10 text-error' => $flashType === 'error',
        ])
    >
        <span>{{ $flashMessage }}</span>
        <button type="button" wire:click="$set('flashMessage', null)" aria-label="{{ __('Descartar aviso') }}" class="shrink-0 rounded-md px-2 py-1 opacity-70 hover:opacity-100">✕</button>
    </div>
@endif
