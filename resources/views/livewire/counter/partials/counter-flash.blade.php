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
{{-- Prompt 234 — a SUCCESS auto-dismisses; an error does not.

     The owner, after waiving a fee: *"notifications should only be used if really needed, and not cover the
     basket like this too."* Two answers. Most of that noise is gone at the CALL SITES (see DECISIONS for the
     kept/killed list — a success that restates a state change the screen already shows was never information).
     What survives carries figures, and it hides itself after a few seconds so it stops spending the basket's
     height.

     Hidden CLIENT-SIDE only: the message is in the live region when it renders, so it is announced once
     whatever happens next, and a timer that fired a Livewire round trip would spend a request to say nothing.
     An error persists — prompt 60's refusal must stay until it is read or resolved. --}}
{{-- Prompt 279 — two optional inputs, both for a message rendered INSIDE a form card rather than at a fixed spot:
       · `$nonce` joins the key. The key is otherwise the message itself, so the SAME confirmation twice in a row
         (two 10,00 € entradas) morphed onto the old, already-faded element and showed nothing the second time.
       · `$reveal` scrolls the message into view only if it is not already — `block: 'nearest'`, instant, so a
         visible message never moves the page and there is no animation to gate on reduced motion. --}}
{{-- Post-296 completeness D3 — every counter screen now numbers its flashes (`$flashSeq`), so the nonce defaults to it:
     a screen that includes this partial no longer has to remember to pass it. --}}
@php($nonce = $nonce ?? ($flashSeq ?? null))
@php($revealJs = ($reveal ?? false) ? "\$nextTick(() => \$el.scrollIntoView({ block: 'nearest' }));" : '')
@if ($flashMessage)
    <div
        wire:key="flash-{{ $flashType }}-{{ md5($flashMessage) }}{{ isset($nonce) ? '-'.$nonce : '' }}"
        {{-- Prompt 374 — a success that says which box the cash goes in waits for the next action (`$flashKeeps`). --}}
        @if ($flashType === 'success' && ! ($flashKeeps ?? false))
            x-data="{ show: true }"
            x-show="show"
            x-init="{{ $revealJs }} setTimeout(() => show = false, 6000)"
        @elseif ($revealJs !== '')
            x-data
            x-init="{{ $revealJs }}"
        @endif
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
            {{-- Prompt 324 — in *Modo formación* a success says it was practice: "Visita liquidada … (práctica)". --}}
            <span>{{ $flashMessage }}@if ($flashType === 'success' && \App\Support\TrainingMode::active()) <strong data-practice-suffix>{{ __('(práctica)') }}</strong>@endif</span>
            {{-- The outcome rides INSIDE this live region, so a successful commit is announced once, as one
                 message, with the figures (prompt 202 on top of 199's one-region rule). --}}
            @include('livewire.counter.partials.settled-outcome')
        </div>
        {{-- 44×44 (prompt 234). Measured at 27×28 the first time a harness state carried a flash at all — the
             one control on this block, on a tablet, under the floor since 192. Nothing had a flash on screen
             to measure until the column's own geometry harness gained one. --}}
        <button type="button" wire:click="$set('flashMessage', null)" aria-label="{{ __('Descartar aviso') }}" class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md transition hover:bg-black/5 dark:hover:bg-white/5">✕</button>
    </div>
@endif
