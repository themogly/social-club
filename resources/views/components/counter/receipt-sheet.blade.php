{{-- Prompt 252 — the receipt as a SHEET inside the counter, never a new tab.

     A new tab on an Android tablet is a HIDDEN tab: Chrome switches to it, the counter is gone, and the only
     way back is a control the operator was never taught (and a kiosk may hide). So the ticket opens ON the POS
     as a modal over the work, with the existing receipt view in an <iframe> pointed at the unchanged receipt
     route — the route, its Gate::authorize and the emailed link are all untouched; only WHERE it is shown
     changes. `Imprimir` calls the IFRAME's print(), so the ticket prints exactly as it did in a tab.

     ONE component, two consumers (the dispensary POS and the bar POS). A third POS must reuse it, not hand-roll
     a second — `ReceiptSheetConsumersTest` enforces that.

     `x-show`, never `x-if` (prompt 245): this renders inside a Livewire-morphed POS view, and an `x-if` clone
     is owned by neither Livewire nor Alpine, so a morph duplicates it. The markup is present once and toggled.

     The Android back gesture CLOSES the sheet instead of leaving the page: `history.pushState` on open, and a
     `popstate` listener closes. That is the whole point of the prompt for a device with no visible chrome. --}}
@props([
    'url',
    'label' => __('Ver / imprimir recibo'),
    'heading' => __('Recibo'),
])

<div
    data-receipt-sheet
    x-data="{
        isOpen: false,
        src: 'about:blank',
        base: @js($url),
        open() {
            {{-- `embedded=1` tells the receipt page to hide its own 'Volver al mostrador' bar (prompt 252 §2):
                 inside the sheet the way back is Cerrar, and a link that navigated the IFRAME would be broken. --}}
            this.src = this.base + (this.base.includes('?') ? '&' : '?') + 'embedded=1';
            this.isOpen = true;
            history.pushState({ receiptSheet: true }, '');
            this.$nextTick(() => this.$refs.closeButton?.focus());
        },
        close() {
            if (! this.isOpen) return;
            this.isOpen = false;
            this.src = 'about:blank';
            if (history.state?.receiptSheet) history.back();
        },
        print() {
            const frame = this.$refs.frame;
            if (! frame) return;
            frame.contentWindow?.focus();
            frame.contentWindow?.print();
        },
        init() {
            window.addEventListener('popstate', () => {
                if (this.isOpen) { this.isOpen = false; this.src = 'about:blank'; }
            });
        },
    }"
>
    <button
        type="button"
        data-receipt-open
        @click="open()"
        class="inline-flex h-11 items-center justify-center rounded-xl border border-line bg-surface px-4 text-sm font-semibold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
    >{{ $label }}</button>

    <div
        x-show="isOpen"
        x-cloak
        @keydown.escape.window="close()"
        role="dialog"
        aria-modal="true"
        aria-label="{{ $heading }}"
        class="fixed inset-0 z-40 flex items-stretch justify-center bg-slate-950/60 p-4 backdrop-blur-sm sm:items-center"
    >
        {{-- Backdrop as its own layer: a tap beside the panel closes; a tap inside does not. --}}
        <button type="button" data-receipt-backdrop @click="close()" tabindex="-1" aria-hidden="true" class="absolute inset-0 h-full w-full cursor-default"></button>

        <div class="counter-modal-pop relative my-auto flex max-h-[min(920px,94svh)] w-[min(460px,100%)] flex-col overflow-hidden rounded-[18px] border border-line bg-surface shadow-2xl dark:border-slate-700 dark:bg-slate-900">
            <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3 dark:border-slate-800">
                <h2 class="text-base font-semibold">{{ $heading }}</h2>
                <div class="flex items-center gap-2">
                    <button type="button" data-receipt-print @click="print()" class="inline-flex h-10 items-center justify-center rounded-lg bg-brand px-4 text-sm font-semibold text-white transition hover:bg-brand-dark">{{ __('Imprimir') }}</button>
                    <button type="button" data-receipt-close x-ref="closeButton" @click="close()" class="inline-flex h-10 items-center justify-center rounded-lg border border-line px-4 text-sm font-semibold text-ink-muted transition hover:bg-surface-alt dark:border-slate-700 dark:text-slate-300">{{ __('Cerrar') }}</button>
                </div>
            </div>

            {{-- The unchanged receipt route, shown in place. `about:blank` until opened, so nothing loads (and no
                 access is logged) until the operator asks for it. --}}
            <iframe
                data-receipt-frame
                x-ref="frame"
                x-bind:src="src"
                title="{{ $heading }}"
                class="h-[70svh] w-full flex-1 border-0 bg-white"
            ></iframe>
        </div>
    </div>
</div>
