{{-- Prompt 353 — /docs on a phone that is not signed in: the PIN, and nothing else. The counter's own pad
     (x-counter.pin-keys + window.pinEntry), posting a plain form. One message for every failure. --}}
<x-layouts.guides>
    <div class="flex justify-center py-4" data-docs-pin
         x-data="{
            keysLocked: false,
            ...window.pinEntry({ one: @js(__('1 dígito introducido')), many: @js(__(':count dígitos introducidos')) }),
            submit() { if (this.pin !== '') this.$refs.form.submit() },
         }"
         x-on:keydown.window="if (/^[0-9]$/.test($event.key)) push($event.key); else if ($event.key === 'Backspace') back(); else if ($event.key === 'Enter') { $event.preventDefault(); submit() }">
        <div data-pin-pad class="w-full max-w-xs rounded-2xl border border-line bg-surface p-6 shadow-xl dark:border-slate-800 dark:bg-slate-900">
            <div class="flex flex-col items-center text-center">
                <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-tint text-brand dark:bg-slate-800">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-6 w-6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25"/></svg>
                </span>
                <h1 class="mt-3 text-base font-semibold">{{ __('Guías del club') }}</h1>
                <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">{{ __('Introduce tu PIN para ver las guías.') }}</p>
            </div>

            <x-counter.pin-keys>
                @if (session('docs_error') || $lockedOut)
                    <p role="alert" data-docs-error class="mt-3 rounded-lg bg-error/10 px-3 py-2 text-center text-sm font-medium text-error">{{ __('PIN incorrecto') }}</p>
                @endif
            </x-counter.pin-keys>

            <form method="POST" action="{{ route('guides.docs.signin') }}" x-ref="form" x-on:submit.prevent="submit()">
                @csrf
                <input type="hidden" name="pin" x-bind:value="pin">
                <button type="button" data-docs-submit x-on:click="submit()"
                        class="mt-4 inline-flex h-12 min-h-[2.75rem] w-full items-center justify-center rounded-lg bg-brand text-sm font-semibold text-white transition hover:bg-brand-dark">{{ __('Entrar') }}</button>
            </form>
        </div>
    </div>
</x-layouts.guides>
