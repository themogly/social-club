{{-- Prompt 262 — a member's ID scan as a SHEET inside the counter, never a new tab (252's rule, the receipt sheet's
     shape). Driven by the component's `documentViewUrl`: `viewDocument()` mints a fresh short-lived signed URL on each
     tap and this opens on it; Cerrar / the backdrop / Escape / the Android back gesture call `closeDocument()`. The
     scan is streamed by the same access-logged endpoint the panel uses — this only decides WHERE it is shown.
     `x-show`, never `x-if` (prompt 245). --}}
<div
    data-document-sheet
    x-data="{
        get url() { return $wire.documentViewUrl },
        init() {
            this.$watch('url', (value) => {
                if (value) { history.pushState({ documentSheet: true }, ''); this.$nextTick(() => this.$refs.closeButton?.focus()); }
            });
            window.addEventListener('popstate', () => { if (this.url) $wire.closeDocument(); });
        },
        close() {
            if (! this.url) return;
            $wire.closeDocument();
            if (history.state?.documentSheet) history.back();
        },
    }"
>
    <div
        x-show="url"
        x-cloak
        @keydown.escape.window="close()"
        role="dialog"
        aria-modal="true"
        aria-label="{{ __('Documento de identidad') }}"
        class="fixed inset-0 z-40 flex items-stretch justify-center bg-slate-950/60 p-4 backdrop-blur-sm sm:items-center"
    >
        <button type="button" @click="close()" tabindex="-1" aria-hidden="true" class="absolute inset-0 h-full w-full cursor-default"></button>

        <div class="counter-modal-pop relative my-auto flex max-h-[min(920px,94svh)] w-[min(720px,100%)] flex-col overflow-hidden rounded-[18px] border border-line bg-surface shadow-2xl dark:border-slate-700 dark:bg-slate-900">
            <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3 dark:border-slate-800">
                <h2 class="text-base font-semibold">{{ __('Documento de identidad') }}</h2>
                <button type="button" data-document-close x-ref="closeButton" @click="close()" class="inline-flex h-10 items-center justify-center rounded-lg border border-line px-4 text-sm font-semibold text-ink-muted transition hover:bg-surface-alt dark:border-slate-700 dark:text-slate-300">{{ __('Cerrar') }}</button>
            </div>
            <iframe x-bind:src="url || 'about:blank'" title="{{ __('Documento de identidad') }}" class="h-[70svh] w-full flex-1 bg-white"></iframe>
            <p class="border-t border-line px-4 py-2 text-[11px] text-ink-muted dark:border-slate-800 dark:text-slate-400">{{ __('Cada consulta queda registrada con tu nombre.') }}</p>
        </div>
    </div>
</div>
