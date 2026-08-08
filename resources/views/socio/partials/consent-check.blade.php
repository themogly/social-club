{{-- A tickable row on the applicant's form — ONE construction for all three (prompt 217).

     Measured at 390×844 with touch, which is the size that matters: this is the product's one genuinely
     phone-first surface, an applicant on their own device or holding the club's tablet in portrait. The two
     consent rows were 290×40 — 4px short — and **`is_therapeutic` was a bare 16×20 tap target**, a
     therapeutic declaration that is genuinely hard to tick and, being a health-adjacent fact, one where
     mis-ticking in either direction matters more than most fields.

     The LABEL is the target: `min-h-11` with the padding inside it, so the whole row including the words is
     tappable. The checkbox glyph is unchanged — this grows the hit area, not the design.

     Expects: $name, $label. Optional: $required, $checked, $tone. --}}
@props(['name', 'label', 'required' => false, 'checked' => false, 'tone' => 'plain'])

<label @class([
    'flex min-h-11 cursor-pointer items-start gap-2 rounded-lg py-2.5 text-sm',
    'bg-surface-alt px-3 dark:bg-slate-950' => $tone === 'card',
])>
    <input
        type="checkbox"
        name="{{ $name }}"
        value="1"
        @if ($required) required @endif
        @checked($checked)
        @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror
        class="mt-0.5 h-5 w-5 shrink-0 rounded border-line text-brand dark:border-slate-600 dark:bg-slate-900"
    >
    <span class="text-ink-muted dark:text-slate-300">{{ $label }}</span>
</label>
