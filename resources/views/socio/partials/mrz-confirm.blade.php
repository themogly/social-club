{{-- Prompt 179 — a field the browser read off the document, awaiting confirmation.

     This is what makes an imperfect reader safe. Prompt 128 gated the prefill on a ≥~90% read rate, and
     that gate rested on an assumption: that a prefilled value is TRUSTED. Remove the assumption and the
     read rate stops being load-bearing — a wrong read costs a correction, not a wrong row in the libro de
     socios. The applicant is the check, which is why the confirmation is not optional and is enforced
     server-side as well as here.

     Expects: $field. --}}
@if (isset($prefill[$field]))
    <div data-mrz-prefilled="{{ $field }}" class="mt-1.5 rounded-lg border border-warning/40 bg-warning/5 p-2">
        <p class="text-[11px] font-medium text-warning">{{ __('Leído de tu documento. Compruébalo.') }}</p>
        <label class="mt-1 flex min-h-11 items-center gap-2 text-sm">
            <input
                type="checkbox"
                name="mrz_confirmed[{{ $field }}]"
                value="1"
                data-mrz-confirm="{{ $field }}"
                @checked(old('mrz_confirmed.'.$field))
                class="h-6 w-6 shrink-0 rounded border-line text-brand focus:ring-2 focus:ring-brand/40 dark:border-slate-600"
            >
            <span>{{ __('Es correcto') }}</span>
        </label>
    </div>
@endif
