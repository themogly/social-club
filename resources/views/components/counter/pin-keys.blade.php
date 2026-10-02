{{-- Prompt 353 — THE PIN pad's keys: the masked dots (and the count a screen reader hears), then whatever the caller
     puts between (status lines, feedback), then the 12 keys at the counter's 44x44 floor. One pad in the codebase
     (CounterSurfaceTest): the counter's lock surface and /docs both use it. The parent's Alpine state supplies `pin`,
     `push`, `back`, `clear` and `digitsLabel` (window.pinEntry in app.js) and `keysLocked`. --}}
{{-- Masked, client-side display of the digits entered so far. --}}
<div class="mt-4 flex h-11 items-center justify-center gap-1.5 rounded-lg border border-line bg-surface-alt dark:border-slate-700 dark:bg-slate-800" aria-hidden="true">
    <template x-for="i in pin.length" :key="i">
        <span class="h-2.5 w-2.5 rounded-full bg-ink dark:bg-slate-200"></span>
    </template>
    <span x-show="pin.length === 0" class="text-sm text-ink-muted dark:text-slate-400">••••</span>
</div>
{{-- The dots are aria-hidden, so the COUNT is announced instead (never the digits). --}}
<p data-pin-count class="sr-only" aria-live="polite" x-text="digitsLabel(pin.length)"></p>

{{ $slot }}

{{-- Every control at the counter's 44x44 floor (prompts 116/132) — including this pad's own
     confirm, which was 155x42 in the partial this replaces. --}}
<div class="mt-4 grid grid-cols-3 gap-2">
    @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9'] as $digit)
        <button type="button" @click="push('{{ $digit }}')" x-bind:disabled="keysLocked" class="disabled:opacity-50 min-h-[2.75rem] min-w-[2.75rem] rounded-lg border border-line py-3 text-lg font-semibold transition hover:bg-brand-tint hover:text-brand dark:border-slate-700 dark:hover:bg-slate-800 dark:hover:text-white">{{ $digit }}</button>
    @endforeach
    <button type="button" @click="clear()" x-bind:disabled="keysLocked" class="disabled:opacity-50 min-h-[2.75rem] min-w-[2.75rem] rounded-lg border border-line py-3 text-sm font-medium text-ink-muted transition hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">{{ __('Borrar') }}</button>
    <button type="button" @click="push('0')" x-bind:disabled="keysLocked" class="disabled:opacity-50 min-h-[2.75rem] min-w-[2.75rem] rounded-lg border border-line py-3 text-lg font-semibold transition hover:bg-brand-tint hover:text-brand dark:border-slate-700 dark:hover:bg-slate-800 dark:hover:text-white">0</button>
    <button type="button" @click="back()" x-bind:disabled="keysLocked" aria-label="{{ __('Retroceso') }}" class="disabled:opacity-50 min-h-[2.75rem] min-w-[2.75rem] rounded-lg border border-line py-3 text-lg transition hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">⌫</button>
</div>
