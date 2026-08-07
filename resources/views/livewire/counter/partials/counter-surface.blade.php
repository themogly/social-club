{{--
    THE counter's one full-screen surface (prompt 173). Three modes, one implementation.

    It replaces TWO partials that each carried their own PIN pad with character-identical Alpine state —
    `operator-strip.blade.php` (inline, in normal flow) and `lock-overlay.blade.php` (fixed) — both included
    by all five screens. The drift the design warned about had already happened; this deletes it.

      locked        the idle timer or the lock button signed an identified operator out (prompt 120)
      unidentified  no operator yet: start of a shift, or after a switch
      handed over   the tablet is in an applicant's hands (prompt 174 fills the surface)

    The three share opacity, the counter beneath being unreachable, and the PIN as the way back. They differ
    only in what fills them and what ends them.

    Why this is full-screen at all: the old strip was an inline block in normal flow — 49px closed, 521px
    open — so opening the pad pushed everything below it down. On the till at 1180x820 "Abrir caja" moved
    from y=381 (50% down) to y=805 (102%) and never came back: you tapped Identificarse to be allowed to
    press the button, and the button left the screen.

    OPAQUE, not translucent. Prompt 120's entry claimed an opaque surface while the markup painted
    `bg-surface-alt/95` with a blur. That is a readability problem when a tablet is left unattended and a
    real one in handed-over mode, where a person who is not a member is holding the device with the
    counter behind them. The claim and the markup now agree.

    NOT the security boundary: `requireOperator()` still refuses every write server-side. This is
    presentation, exactly as prompt 120 said.
--}}
@php($surfaceMode = $this->surfaceMode())
@php($handoverReturnUrl = \App\Support\CounterHandover::returnUrl())

<div
    data-counter-surface
    data-surface-mode="{{ $surfaceMode ?? 'none' }}"
    x-cloak
    x-data="{
        pin: '',
        serverMode: @js($surfaceMode),
        {{-- Prompt 187 defect 2: handed-over mode shipped with NO control of any kind, so aborting a handover
             was impossible — a staff member whose applicant walks away, gives up, or turns out to be underage
             had a terminal they could not recover without clearing the session. 173 required "the PIN is how
             you get back"; this is that PIN, kept behind one deliberate tap so the applicant is not invited
             to press it, but present, focusable and labelled rather than hidden behind a secret gesture. --}}
        staffPad: false,
        push(d) { if (this.pin.length < 8) this.pin += d },
        back() { this.pin = this.pin.slice(0, -1) },
        clear() { this.pin = '' },
        submit() { if (this.pin === '') return; $wire.operatorPin = this.pin; this.pin = ''; $wire.unlockOperator() },
        {{-- Handed over outranks everything: the applicant must not be shown a lock screen mid-form.
             Otherwise a client-side idle lock outranks the server's 'no operator yet'. --}}
        get mode() {
            if (this.serverMode === 'handover') return 'handover'
            if ($store.counter.locked) return 'locked'
            return this.serverMode
        },
        get open() { return this.mode !== null },
        {{-- The pad is the same pad in all three modes; only what it says differs. --}}
        get padVisible() { return this.mode === 'locked' || this.mode === 'unidentified' || (this.mode === 'handover' && this.staffPad) },
    }"
    x-effect="if (mode !== 'handover') staffPad = false"
    x-show="open"
    x-on:counter-unlocked.window="$store.counter.unlocked()"
    @keydown.window.enter="open && padVisible && submit()"
    class="fixed inset-0 z-50 flex items-center justify-center bg-surface-alt p-4 dark:bg-slate-950"
    role="dialog"
    aria-modal="true"
    x-bind:aria-label="padVisible
        ? (mode === 'handover' ? @js(__('Recuperar el mostrador')) : (mode === 'locked' ? @js(__('Pantalla bloqueada')) : @js(__('¿Quién está trabajando?'))))
        : @js(__('Alta de socio/a'))"
>
    {{-- HANDED OVER — an applicant is holding the tablet. Nothing of the club's is on screen: no member,
         no sede, no operator, no basket.

         This is the RESTING STATE (prompt 187). It used to promise "El formulario se abrirá aquí" — a form
         that is never coming: prompt 174 sends the applicant to the real tokenised route
         (handOverForAlta redirects to the invite URL), so this surface is what shows when they LEAVE that
         form — the back button, or closing it. The reported symptom was exactly that: "if I close the form I
         get stuck on this page." A promise of a form that will not arrive is worse than saying nothing, so
         it now says the true thing and offers the two ways out that actually exist.

         Handed-over mode is TERMINAL-WIDE and deliberately still is. It describes who is holding the device,
         not which screen is open, which is why prompt 173 made it session-backed — and why
         EnforceCounterHandover can allowlist all five counter screens: each renders only this surface. Making
         it screen-specific would mean a counter screen rendering the COUNTER to an applicant, which is the
         leak the whole mode exists to prevent. So the fix is that this surface is complete everywhere, not
         that it stops persisting. --}}
    <template x-if="mode === 'handover' && ! staffPad">
        <div data-handover-surface class="flex w-full max-w-md flex-col items-center text-center">
            <h2 class="text-xl font-semibold">{{ __('Alta de socio/a') }}</h2>
            <p class="mt-2 text-sm text-ink-muted dark:text-slate-400">{{ __('Rellena tus datos en esta tablet. Cuando termines, devuélvela al personal.') }}</p>

            @if ($handoverReturnUrl !== null)
                {{-- Their OWN form, by the token they already hold — nothing of the club's is in this link. --}}
                <a
                    href="{{ $handoverReturnUrl }}"
                    data-handover-resume
                    class="mt-6 inline-flex min-h-[2.75rem] items-center justify-center rounded-lg bg-brand px-5 text-sm font-semibold text-white transition hover:bg-brand-dark"
                >{{ __('Continuar con mi solicitud') }}</a>
            @endif

            {{-- The way back for STAFF. Present, focusable and labelled — an invisible gesture would be
                 undiscoverable and unreachable by keyboard or assistive tech — but muted and set apart, so it
                 reads as "not for you" to the person holding the tablet. It only opens a PIN pad, which they
                 cannot pass. --}}
            <button
                type="button"
                data-handover-staff
                @click="staffPad = true; $nextTick(() => $refs.pinPad?.focus())"
                class="mt-10 min-h-[2.75rem] rounded-lg px-4 text-xs font-medium text-ink-muted underline underline-offset-4 transition hover:text-ink dark:text-slate-500 dark:hover:text-slate-300"
            >{{ __('Personal del club') }}</button>
        </div>
    </template>

    {{-- LOCKED, UNIDENTIFIED, and HANDED-OVER-with-the-staff-pad-open — the same PIN pad, the same
         UnlockOperator call and therefore the same throttle, differing only in what it says. --}}
    <template x-if="padVisible">
        <div class="w-full max-w-xs rounded-2xl border border-line bg-surface p-6 shadow-xl dark:border-slate-800 dark:bg-slate-900">
            <div class="flex flex-col items-center text-center">
                <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-tint text-brand dark:bg-slate-800">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-6 w-6" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 0h10.5a2.25 2.25 0 0 1 2.25 2.25v6.75a2.25 2.25 0 0 1-2.25 2.25H6.75a2.25 2.25 0 0 1-2.25-2.25v-6.75a2.25 2.25 0 0 1 2.25-2.25Z"/>
                    </svg>
                </span>
                <h2 data-surface-heading class="mt-3 text-base font-semibold"
                    x-text="mode === 'handover' ? @js(__('Recuperar el mostrador')) : (mode === 'locked' ? @js(__('Pantalla bloqueada')) : @js(__('¿Quién está trabajando?')))"></h2>
                <p class="mt-1 text-sm text-ink-muted dark:text-slate-400"
                   x-text="mode === 'handover' ? @js(__('Introduce tu PIN para finalizar la entrega y volver al mostrador.')) : (mode === 'locked' ? @js(__('Introduce tu PIN para continuar. El trabajo en curso se conserva.')) : @js(__('Introduce tu PIN para identificarte en el mostrador.')))"></p>
            </div>

            {{-- Masked, client-side display of the digits entered so far. --}}
            <div class="mt-4 flex h-11 items-center justify-center gap-1.5 rounded-lg border border-line bg-surface-alt dark:border-slate-700 dark:bg-slate-800" aria-hidden="true">
                <template x-for="i in pin.length" :key="i">
                    <span class="h-2.5 w-2.5 rounded-full bg-ink dark:bg-slate-200"></span>
                </template>
                <span x-show="pin.length === 0" class="text-sm text-ink-muted dark:text-slate-500">••••</span>
            </div>

            @if ($operatorFeedback !== null)
                <p data-counter-surface-feedback class="mt-3 rounded-lg bg-error/10 px-3 py-2 text-center text-sm font-medium text-error">{{ $operatorFeedback }}</p>
            @endif

            @if ($this->operatorLockedOut())
                <p class="mt-3 text-center text-sm text-ink-muted dark:text-slate-400">{{ __('Demasiados intentos. Inténtalo en :s s.', ['s' => $this->operatorLockoutSeconds()]) }}</p>
            @endif

            {{-- Every control at the counter's 44x44 floor (prompts 116/132) — including this pad's own
                 confirm, which was 155x42 in the partial this replaces. --}}
            <div class="mt-4 grid grid-cols-3 gap-2">
                @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9'] as $digit)
                    <button type="button" @click="push('{{ $digit }}')" class="min-h-[2.75rem] min-w-[2.75rem] rounded-lg border border-line py-3 text-lg font-semibold transition hover:bg-brand-tint hover:text-brand dark:border-slate-700 dark:hover:bg-slate-800 dark:hover:text-white">{{ $digit }}</button>
                @endforeach
                <button type="button" @click="clear()" class="min-h-[2.75rem] min-w-[2.75rem] rounded-lg border border-line py-3 text-sm font-medium text-ink-muted transition hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">{{ __('Borrar') }}</button>
                <button type="button" @click="push('0')" class="min-h-[2.75rem] min-w-[2.75rem] rounded-lg border border-line py-3 text-lg font-semibold transition hover:bg-brand-tint hover:text-brand dark:border-slate-700 dark:hover:bg-slate-800 dark:hover:text-white">0</button>
                <button type="button" @click="back()" aria-label="{{ __('Retroceso') }}" class="min-h-[2.75rem] min-w-[2.75rem] rounded-lg border border-line py-3 text-lg transition hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">⌫</button>
            </div>

            <button type="button" data-counter-surface-unlock x-ref="pinPad" @click="submit()" class="mt-4 min-h-[2.75rem] h-12 w-full rounded-lg bg-brand text-sm font-semibold text-white transition hover:bg-brand-dark"
                    x-text="mode === 'handover' ? @js(__('Recuperar el mostrador')) : (mode === 'locked' ? @js(__('Desbloquear')) : @js(__('Identificarse')))"></button>

            {{-- Opened by mistake, or the staff member changed their mind: hand the tablet back to the
                 applicant rather than leaving them facing a PIN pad. Handover mode only. --}}
            <template x-if="mode === 'handover'">
                <button
                    type="button"
                    data-handover-staff-cancel
                    @click="staffPad = false; clear()"
                    class="mt-3 min-h-[2.75rem] w-full rounded-lg px-4 text-xs font-medium text-ink-muted transition hover:text-ink dark:text-slate-500 dark:hover:text-slate-300"
                >{{ __('Volver a la pantalla del solicitante') }}</button>
            </template>
        </div>
    </template>
</div>
