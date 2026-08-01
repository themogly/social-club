{{--
    Idle-lock overlay (prompt 120). @include-d by every counter screen (like the operator strip), so its PIN pad
    binds to the enclosing Livewire component (IdentifiesOperator). It covers the WHOLE viewport with an OPAQUE
    surface the moment `$store.counter.locked` flips — after the shared idle timer fires or the "lock now" button
    is pressed — so an unattended tablet stops showing member data. Locking also signs the operator out
    server-side (see IdentifiesOperator::lockCounter), so a commit is refused by requireOperator(), not merely
    hidden. Unlocking is the SAME PIN pad + throttle as identifying an operator: on success the trait dispatches
    `counter-unlocked`, which lifts the overlay with the basket and session untouched.
--}}
<div
    data-counter-lock
    x-cloak
    x-show="$store.counter.locked"
    x-on:counter-unlocked.window="$store.counter.unlocked()"
    x-data="{ pin: '', push(d){ if (this.pin.length < 8) this.pin += d }, back(){ this.pin = this.pin.slice(0, -1) }, clear(){ this.pin = '' }, submit(){ if (this.pin === '') return; $wire.operatorPin = this.pin; this.pin = ''; $wire.unlockOperator(); } }"
    @keydown.window.enter="$store.counter.locked && submit()"
    class="fixed inset-0 z-50 flex items-center justify-center bg-surface-alt/95 backdrop-blur-sm dark:bg-slate-950/95"
    role="dialog"
    aria-modal="true"
    aria-label="{{ __('Pantalla bloqueada') }}"
>
    <div class="w-full max-w-xs rounded-2xl border border-line bg-surface p-6 shadow-xl dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-col items-center text-center">
            <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-tint text-brand dark:bg-slate-800">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-6 w-6" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 0h10.5a2.25 2.25 0 0 1 2.25 2.25v6.75a2.25 2.25 0 0 1-2.25 2.25H6.75a2.25 2.25 0 0 1-2.25-2.25v-6.75a2.25 2.25 0 0 1 2.25-2.25Z"/>
                </svg>
            </span>
            <h2 class="mt-3 text-base font-semibold">{{ __('Pantalla bloqueada') }}</h2>
            <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">{{ __('Introduce tu PIN para continuar. El trabajo en curso se conserva.') }}</p>
        </div>

        {{-- Masked, client-side display of the digits entered so far. --}}
        <div class="mt-4 flex h-11 items-center justify-center gap-1.5 rounded-lg border border-line bg-surface-alt dark:border-slate-700 dark:bg-slate-800" aria-hidden="true">
            <template x-for="i in pin.length" :key="i">
                <span class="h-2.5 w-2.5 rounded-full bg-ink dark:bg-slate-200"></span>
            </template>
            <span x-show="pin.length === 0" class="text-sm text-ink-muted dark:text-slate-500">••••</span>
        </div>

        @if ($operatorFeedback !== null)
            <p data-counter-lock-feedback class="mt-3 rounded-lg bg-error/10 px-3 py-2 text-center text-sm font-medium text-error">{{ $operatorFeedback }}</p>
        @endif

        @if ($this->operatorLockedOut())
            <p class="mt-3 text-center text-sm text-ink-muted dark:text-slate-400">{{ __('Demasiados intentos. Inténtalo en :s s.', ['s' => $this->operatorLockoutSeconds()]) }}</p>
        @endif

        <div class="mt-4 grid grid-cols-3 gap-2">
            @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9'] as $digit)
                <button type="button" @click="push('{{ $digit }}')" class="rounded-lg border border-line py-3 text-lg font-semibold transition hover:bg-brand-tint hover:text-brand dark:border-slate-700 dark:hover:bg-slate-800 dark:hover:text-white">{{ $digit }}</button>
            @endforeach
            <button type="button" @click="clear()" class="rounded-lg border border-line py-3 text-sm font-medium text-ink-muted transition hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">{{ __('Borrar') }}</button>
            <button type="button" @click="push('0')" class="rounded-lg border border-line py-3 text-lg font-semibold transition hover:bg-brand-tint hover:text-brand dark:border-slate-700 dark:hover:bg-slate-800 dark:hover:text-white">0</button>
            <button type="button" @click="back()" aria-label="{{ __('Retroceso') }}" class="rounded-lg border border-line py-3 text-lg transition hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">⌫</button>
        </div>

        <button type="button" data-counter-lock-unlock @click="submit()" class="mt-4 h-12 w-full rounded-lg bg-brand text-sm font-semibold text-white transition hover:bg-brand-dark">{{ __('Desbloquear') }}</button>
    </div>
</div>
