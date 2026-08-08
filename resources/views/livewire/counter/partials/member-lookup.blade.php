{{--
    THE member lookup (prompt 194) — one field, one label, on every counter screen that identifies a socio.

    It replaces seven inputs across five screens. Two of those screens stacked a scan box directly above a
    name box, each of which already did the other's job; the other three offered a name box with no scan
    affordance, so a card scanned there landed in a name search and found nothing. The operator learned a
    rule nobody designed: that scanning "works on Dispensario but not on Socios".

    **Prompt 204 made it live, and made it a combobox.** 194 shipped it as a form you had to SUBMIT: results
    appeared only after Enter, and the placeholder had to say so. A control that has to teach its own
    keystroke is the defect. It is now `wire:model.live` with a debounce, and carries the ARIA and the
    keyboard behaviour that a list-under-a-textbox is required to have — `role="combobox"`, an owned
    `listbox`, ↑/↓ through the options, Enter to take the active one, Escape to close. Screen-reader users
    got NO announcement of the results before this: the list simply appeared in the DOM, unowned and
    unannounced, next to an input that claimed to be a plain textbox.

    Enter with no option active still submits, which is what keeps a scanner working: a wedge reader types
    its token and presses Return, no arrow key is ever involved, and the submit path resolves it as a token
    exactly as before.

    The behaviour is in App\Livewire\Counter\Concerns\FindsMembers. This is only its surface.

    Deliberately WITHOUT card chrome — the caller wraps it. A blocking state is already the whole screen and
    does not want a card inside a card; a cart column does.

    $autofocus — the screen whose entire purpose is identifying somebody focuses this; a cart column does not
    steal focus from the basket.

    $large (prompt 221) — the screen where finding somebody IS the page, rather than one control in a cart
    column: a taller field with a search glyph, and the label read by screen readers but not repeated on
    screen, because the page's own H1 and subtitle already say it. Presentation only; every other host is
    byte-identical to before.
--}}
@php($results = $this->lookupResults())

<div
    x-data="{
        active: -1,

        options() { return Array.from($el.querySelectorAll('[data-member-lookup-result]')) },

        /* Read the options from the DOM at keypress time rather than snapshotting a count into x-data.
           Livewire re-renders this list under a persistent Alpine island, so anything captured at init goes
           stale the moment the operator types another letter (prompt 188's lesson, in miniature). */
        move(step) {
            const options = this.options()
            if (! options.length) return
            this.active = (this.active + step + options.length) % options.length
            this.sync(options)
        },

        sync(options) {
            options.forEach((el, i) => el.setAttribute('aria-selected', i === this.active ? 'true' : 'false'))
            const current = options[this.active]
            if (current) {
                $refs.field.setAttribute('aria-activedescendant', current.id)
                current.scrollIntoView({ block: 'nearest' })
            } else {
                $refs.field.removeAttribute('aria-activedescendant')
            }
        },

        /* True when an option was taken — the caller then swallows Enter so the form does not ALSO submit. */
        choose() {
            const current = this.options()[this.active]
            if (! current) return false
            current.click()
            this.reset()
            return true
        },

        reset() {
            this.active = -1
            $refs.field?.removeAttribute('aria-activedescendant')
        },
    }"
>
    <form wire:submit="submitLookup">
        <label for="member-lookup" @class([
            'block text-sm font-medium text-ink-muted dark:text-slate-400',
            'sr-only' => $large ?? false,
        ])>
            {{ $this->lookupLabel() }}
        </label>
        <div class="relative">
            @if ($large ?? false)
                <span aria-hidden="true" class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-lg text-ink-muted dark:text-slate-400">🔍</span>
            @endif
        <input
            id="member-lookup"
            data-member-lookup
            x-ref="field"
            type="text"
            wire:model.live.debounce.250ms="lookup"
            @if ($autofocus ?? false) autofocus @endif
            autocomplete="off"
            spellcheck="false"
            role="combobox"
            aria-controls="member-lookup-results"
            aria-autocomplete="list"
            aria-expanded="{{ $results !== null ? 'true' : 'false' }}"
            @keydown.arrow-down.prevent="move(1)"
            @keydown.arrow-up.prevent="move(-1)"
            @keydown.enter="if (choose()) $event.preventDefault()"
            @keydown.escape.prevent="reset(); $wire.clearLookup()"
            @input="reset()"
            placeholder="{{ $this->lookupPlaceholder() }}"
            @class([
                'w-full rounded-xl border border-line bg-surface text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100',
                'mt-2 h-12 px-4 text-base' => ! ($large ?? false),
                'h-14 pl-12 pr-4 text-lg' => $large ?? false,
            ])
        >
        </div>
    </form>

    {{-- Camera scan is the one part that IS feature-detectable, and the component already does it: it hides
         itself where BarcodeDetector is missing, and needs a secure context. Per-sede, off by default. --}}
    @if ($cameraScanEnabled ?? false)
        <x-counter.camera-scan />
    @endif

    {{-- The listbox is ALWAYS in the DOM so `aria-controls` never dangles; it is empty when there is nothing
         to show. `wire:key` keeps Livewire from reusing rows between two different searches. --}}
    <ul
        id="member-lookup-results"
        role="listbox"
        aria-label="{{ __('Resultados de socios') }}"
        @if ($results !== null) data-member-lookup-results @endif
        @class([
            'mt-2 divide-y divide-line overflow-hidden rounded-xl border border-line dark:divide-slate-800 dark:border-slate-800',
            'hidden' => $results === null,
        ])
    >
        @if ($results !== null)
            @forelse ($results as $index => $result)
                <li role="presentation" wire:key="lookup-{{ $result->id }}">
                    {{-- The whole row is the target, comfortably over the counter's 44px floor. --}}
                    <button
                        type="button"
                        id="member-lookup-option-{{ $index }}"
                        role="option"
                        aria-selected="false"
                        tabindex="-1"
                        wire:click="selectMember('{{ $result->id }}')"
                        data-member-lookup-result
                        class="flex min-h-[2.75rem] w-full items-center justify-between gap-3 bg-surface px-4 py-3 text-left transition hover:bg-surface-alt aria-selected:bg-brand-tint aria-selected:text-brand dark:bg-slate-900 dark:hover:bg-slate-800 dark:aria-selected:bg-slate-800 dark:aria-selected:text-white"
                    >
                        <span class="font-medium">{{ $result->fullName() }}</span>
                        <span class="text-sm text-ink-muted dark:text-slate-400">{{ $result->member_no }}</span>
                    </button>
                </li>
            @empty
                {{-- An empty result is a search MISS, not a scan failure — nothing here has touched the
                     failed-scan throttle (prompt 58). Thirty of these in a shift lock nothing.

                     `role="presentation"` and a live region, not an option: there is nothing to select, and
                     a screen reader hears the miss instead of silently finding a one-item list (prompt 204). --}}
                <li role="presentation" data-member-lookup-empty aria-live="polite" class="bg-surface px-4 py-3 text-sm text-ink-muted dark:bg-slate-900 dark:text-slate-400">{{ __('Sin resultados.') }}</li>
            @endforelse
        @endif
    </ul>
</div>
