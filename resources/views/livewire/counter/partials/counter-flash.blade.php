{{--
    The counter's ONE flash block (prompts 192/193). Extracted so it can render in two places without two
    copies of the markup — the host decides WHERE, never WHAT.

    It has to appear in both because the two cases exclude each other:
      · a blocking state replaces the work (no till, no sede), and the cart column with it — so the reason
        has to be at the top of the page or it has nowhere to go. That is why it lived there originally.
      · otherwise the operator is looking at the cart column, and the answer to "I pressed Charge" belongs
        beside Charge — measured at ~650px away before this, in an 820px viewport.

    Callers pass `$anchor` purely as a test hook so each position is addressable, and `$spacing` because some
    hosts stack with `gap-*` (where a bottom margin would double the gap) and others do not.

    Prompt 202 brought Recepción, Caja and Socios onto it too: they each carried a near-copy of this markup,
    drifting in the ways near-copies drift — Socios had lost its `aria-live` entirely, so a fee confirmation was
    announced to nobody. One block, five screens, one behaviour.
--}}
@if ($flashMessage)
    <div
        wire:key="flash"
        {{ $anchor ?? '' }}
        role="{{ $flashType === 'error' ? 'alert' : 'status' }}"
        aria-live="{{ $flashType === 'error' ? 'assertive' : 'polite' }}"
        @class([
            ($spacing ?? 'mb-4').' flex items-start justify-between gap-3 rounded-xl border px-4 py-3 text-sm font-medium',
            'border-success/30 bg-success/10 text-success' => $flashType === 'success',
            'border-warning/30 bg-warning/10 text-warning' => $flashType === 'warning',
            'border-error/30 bg-error/10 text-error' => $flashType === 'error',
        ])
    >
        <div class="min-w-0 flex-1">
            <span>{{ $flashMessage }}</span>
            {{-- The outcome rides INSIDE this live region, so a successful commit is announced once, as one
                 message, with the figures (prompt 202 on top of 199's one-region rule). --}}
            @include('livewire.counter.partials.settled-outcome')
        </div>
        <button type="button" wire:click="$set('flashMessage', null)" aria-label="{{ __('Descartar aviso') }}" class="shrink-0 rounded-md px-2 py-1 opacity-70 hover:opacity-100">✕</button>
    </div>
@endif
