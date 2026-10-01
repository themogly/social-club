{{-- Prompt 179 — a field the browser read off the document, awaiting confirmation.

     This is what makes an imperfect reader safe. Prompt 128 gated the prefill on a ≥~90% read rate, and
     that gate rested on an assumption: that a prefilled value is TRUSTED. Remove the assumption and the
     read rate stops being load-bearing — a wrong read costs a correction, not a wrong row in the libro de
     socios. The applicant is the check, which is why the confirmation is not optional and is enforced
     server-side as well as here.

     Expects: $field. --}}
{{-- Prompt 346 — always rendered, `hidden` until it applies, so the page can show it after a background read (no reload,
     so the photo stays attached) as well as after the plain POST. Hidden, its checkbox is unchecked and posts nothing. --}}
<div data-mrz-prefilled="{{ $field }}" @unless (isset($prefill[$field])) hidden @endunless class="mt-1.5 rounded-lg border border-warning/40 bg-warning/5 p-2">
    <p class="text-[11px] font-medium text-warning">{{ __('Leído de tu documento. Compruébalo.') }}</p>
    <label class="mt-1 flex min-h-11 items-center gap-2 text-sm">
        <input
            type="checkbox"
            name="mrz_confirmed[{{ $field }}]"
            value="1"
            data-mrz-confirm="{{ $field }}"
            @checked(isset($prefill[$field]) && old('mrz_confirmed.'.$field))
            class="h-6 w-6 shrink-0 rounded border-line text-brand focus:ring-2 focus:ring-brand/40 dark:border-slate-600"
        >
        <span>{{ __('Es correcto') }}</span>
    </label>
</div>
{{-- A value the applicant had already typed is never overwritten: the document's reading is offered instead. --}}
<p data-mrz-offer="{{ $field }}" hidden class="mt-1.5 flex flex-wrap items-center gap-x-2 text-xs text-ink-muted dark:text-slate-400">
    <span>{{ __('En el documento:') }} <strong data-mrz-offer-value class="font-semibold text-ink dark:text-slate-100"></strong></span>
    <span aria-hidden="true">·</span>
    <button type="button" data-mrz-use="{{ $field }}" class="inline-flex min-h-11 items-center font-semibold text-brand underline-offset-2 hover:underline dark:text-slate-100">{{ __('Usar') }}</button>
</p>
