{{-- Prompt 299 — a small sheet over the counter, for a control that should not take up the screen until it is wanted:
     the member card's missing photo (299), the after-sale void (300). The receipt sheet's rules (252), shared:

     - in the page, never a new tab; `x-show`, never `x-if`, because it sits inside a Livewire-morphed POS view (245);
     - the Android back gesture closes it before it leaves the page: `history.pushState` on open, `popstate` closes;
     - Escape and a tap beside the panel close it; focus goes to its close button;
     - a server action can close it with a `counter-sheet-close` browser event naming it, and anything can open it with
       `counter-sheet-open` — which is how a menu item opens one without the sheet living INSIDE the menu (a sheet
       nested in an absolutely positioned popover is trapped under the rest of the screen: 300's lesson).

     `$trigger` (optional) is what opens it (it calls `open()`); the default slot is the content. --}}
@props(['heading', 'name'])

<div
    data-counter-sheet="{{ $name }}"
    x-data="{
        isOpen: false,
        open() {
            this.isOpen = true;
            history.pushState({ counterSheet: @js($name) }, '');
            this.$nextTick(() => this.$refs.sheetClose?.focus());
        },
        close() {
            if (! this.isOpen) return;
            this.isOpen = false;
            if (history.state?.counterSheet === @js($name)) history.back();
        },
        onPop: null,
        init() {
            this.onPop = () => { this.isOpen = false; };
            window.addEventListener('popstate', this.onPop);
        },
        {{-- A sheet the server removes while open (300: the void succeeded, so the line is gone) takes its history
             entry with it, so the next back gesture is not spent on nothing. --}}
        destroy() {
            window.removeEventListener('popstate', this.onPop);
            if (this.isOpen && history.state?.counterSheet === @js($name)) history.back();
        },
    }"
    x-on:counter-sheet-close.window="if (! $event.detail?.name || $event.detail.name === @js($name)) close()"
    x-on:counter-sheet-open.window="if ($event.detail?.name === @js($name)) open()"
    {{ $attributes }}
>
    {{ $trigger ?? '' }}

    <div
        x-show="isOpen"
        x-cloak
        @keydown.escape.window="close()"
        role="dialog"
        aria-modal="true"
        aria-label="{{ $heading }}"
        class="fixed inset-0 z-40 flex items-end justify-center bg-slate-950/60 p-4 backdrop-blur-sm sm:items-center"
    >
        <button type="button" @click="close()" tabindex="-1" aria-hidden="true" class="absolute inset-0 h-full w-full cursor-default"></button>

        <div class="counter-modal-pop relative w-[min(420px,100%)] rounded-[18px] border border-line bg-surface p-4 shadow-2xl dark:border-slate-700 dark:bg-slate-900">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-base font-semibold">{{ $heading }}</h2>
                <button type="button" x-ref="sheetClose" @click="close()" class="inline-flex h-11 items-center justify-center rounded-lg border border-line px-4 text-sm font-semibold text-ink-muted transition hover:bg-surface-alt dark:border-slate-700 dark:text-slate-300">{{ __('Cerrar') }}</button>
            </div>
            <div class="mt-3">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
