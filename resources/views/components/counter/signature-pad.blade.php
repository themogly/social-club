{{-- THE signature pad — one component, every consumer (prompt 220).

     Prompt 113 built this for the dispensation and left the canvas, its Alpine behaviour and its
     capture/clear contract **inline in `dispensary-pos.blade.php`**. That is the same one-consumer shape this
     project has now hit four times — `OpensMemberships` (203, wired only to Socios; 211 wired the rest), the
     MRZ partial (179, included by the public form alone; 215 wired the staff form), the application field
     list (210, two hand-written copies; 215 declared it once), and this. Each shipped green, because the unit
     worked and nothing asserted that everything which SHOULD consume it does.

     So it is a component, and `SignaturePadConsumersTest` iterates its consumers: a third hand-rolled canvas
     fails the suite.

     **The runtime has to be on the page.** Alpine used to arrive only inside Livewire's bundle, so this pad
     worked on the counter and was DEAD MARKUP on the applicant's plain-Blade form — prompt 232 found the
     emailed route could not be completed at all. `resources/js/socio.js` ships it there now.

     **Two mechanics, one pad.** The counter's hosts are Livewire and the applicant's public form is a plain
     POST, so `mode` decides where the drawing goes — `$wire.<capture>()` for a component, or a hidden input
     submitted with the form. Everything an operator or an applicant touches is identical either way, and the
     BYTES take the same road: `DocumentVault`, encrypted, private disk.

     **Contract** — the host supplies:
       · `mode`     `livewire` (default) or `form`
       · `capture`  livewire mode: the method taking a PNG data URL
       · `clear`    livewire mode: the method that discards a stored one
       · `name`     form mode: the hidden input's name (default `signature`)
       · `stored`   truthy when one is already captured
       · `label`    the heading                                     (optional)
       · `hint`     one line under the heading, e.g. what is being signed (optional)

     Behaviour is prompt 113's, unchanged: canvas → base64 → the host stores it through `DocumentVault`,
     encrypted at rest on the private documents disk, never plaintext.

     `data-drawn="1"` lands on the canvas at the first stroke and goes with a clear (prompt 222) — the only
     way anything outside the pad can know a signature exists before it is saved.

     **THE CANVAS IS WHITE IN BOTH THEMES, ON PURPOSE** (prompt 232 — the owner asked whether it should be
     dark). These pixels ARE the stored artefact: encrypted into the vault, reviewed in the admin panel, and
     printed beside scanned paper if an inspection asks for the club's consent evidence. Ink-on-white is the
     document convention, a dark bitmap prints as a black slab, and inverting it at render time would show
     something other than the bytes on file — which an audit artefact must never do. It is a paper strip in a
     framed card, and it should read as one. Do not "fix" it in a theme sweep.

     Touch: `touch-none` on the canvas so a drawn stroke is not a page scroll, and every control clears the
     44px floor — this is used with a finger on a phone (prompt 217's audience) as well as on a tablet. --}}
@props([
    'mode' => 'livewire',
    'capture' => null,
    'clear' => null,
    'name' => 'signature',
    'stored' => false,
    'label' => null,
    'hint' => null,
])

<div data-signature-pad {{ $attributes->merge(['class' => 'mt-4']) }}>
    <p class="text-sm font-medium">{{ $label ?? __('Firma') }}</p>
    @if ($hint)
        <p class="mt-0.5 text-xs text-ink-muted dark:text-slate-400">{{ $hint }}</p>
    @endif

    @if ($stored)
        <div data-signature-captured class="mt-2 flex items-center justify-between rounded-xl border border-success/30 bg-success/10 px-3 py-2 text-sm text-success">
            <span>✓ {{ __('Firma capturada') }}</span>
            @if ($mode === 'livewire')
                <button type="button" wire:click="{{ $clear }}" data-signature-redo
                        class="inline-flex min-h-11 items-center rounded-md px-3 text-success/80 hover:text-success">{{ __('Rehacer') }}</button>
            @endif
        </div>
    @else
        <div
            x-data="{
                drawing: false, ctx: null,
                init() {
                    const c = this.$refs.pad; c.width = c.offsetWidth; c.height = 150;
                    this.ctx = c.getContext('2d'); this.ctx.lineWidth = 2; this.ctx.lineCap = 'round'; this.ctx.strokeStyle = '#2563eb';
                },
                point(e) { const r = this.$refs.pad.getBoundingClientRect(); const t = e.touches ? e.touches[0] : e; return { x: t.clientX - r.left, y: t.clientY - r.top }; },
                {{-- Prompt 222: mark the canvas on the FIRST stroke. A drawn-but-unsaved signature reaches no
                     server and is not an input, so nothing else can tell it apart from a blank pad — and it
                     is the one thing on the form the member themselves did. The counter's close guard reads
                     this attribute; the pad itself does not care. --}}
                start(e) { this.drawing = true; this.$refs.pad.dataset.drawn = '1'; const p = this.point(e); this.ctx.beginPath(); this.ctx.moveTo(p.x, p.y); },
                move(e) { if (! this.drawing) return; const p = this.point(e); this.ctx.lineTo(p.x, p.y); this.ctx.stroke(); },
                stop() { this.drawing = false; },
                wipe() { this.ctx.clearRect(0, 0, this.$refs.pad.width, this.$refs.pad.height); delete this.$refs.pad.dataset.drawn; },
                signed: false,
                save() {
                    const data = this.$refs.pad.toDataURL('image/png');
                    @if ($mode === 'form')
                        this.$refs.field.value = data;
                        this.signed = true;
                    @else
                        $wire.{{ $capture }}(data);
                    @endif
                },
            }"
            class="mt-2"
        >
            <canvas
                x-ref="pad"
                data-signature-canvas
                class="w-full touch-none rounded-xl border border-line bg-white dark:border-slate-700"
                @mousedown="start($event)" @mousemove="move($event)" @mouseup="stop()" @mouseleave="stop()"
                @touchstart.prevent="start($event)" @touchmove.prevent="move($event)" @touchend="stop()"
            ></canvas>
            @if ($mode === 'form')
                {{-- The drawing travels with the form. Empty until the applicant presses Guardar, which is
                     what makes "signed" a deliberate act rather than a stray stroke. --}}
                <input type="hidden" name="{{ $name }}" x-ref="field" value="" data-signature-field>
                <p x-show="signed" x-cloak data-signature-form-ok class="mt-2 text-xs font-medium text-success">✓ {{ __('Firma capturada') }}</p>
            @endif

            <div class="mt-2 flex gap-2">
                <button type="button" @click="wipe()" data-signature-clear
                        class="min-h-11 flex-1 rounded-lg border border-line bg-surface-alt text-sm font-medium text-ink-muted dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ __('Borrar') }}</button>
                <button type="button" @click="save()" data-signature-save
                        class="min-h-11 flex-1 rounded-lg bg-brand text-sm font-semibold text-white hover:bg-brand-dark">{{ __('Guardar firma') }}</button>
            </div>
        </div>
    @endif
</div>
