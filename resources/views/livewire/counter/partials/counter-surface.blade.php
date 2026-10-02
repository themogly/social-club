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
        {{-- Prompt 286 — the one shared PIN behaviour (resources/js/app.js): checking, the greeting, the shake. --}}
        ...window.counterPinCheck(),
        successTemplate: '',
        {{-- Prompt 353 — the digits (pin, push, back, clear, digitsLabel) are the ONE pad's, shared with /docs (app.js). --}}
        ...window.pinEntry({ one: @js(__('1 dígito introducido')), many: @js(__(':count dígitos introducidos')) }),
        {{-- Prompt 188: the mode is READ from $wire, never copied into local state. `serverMode:
             @js($surfaceMode)` snapshotted it at init, and Livewire preserves the DOM across a re-render, so
             x-data never ran again — after identifying, the server said null while Alpine still held
             'unidentified' and the surface stayed up until a manual reload. $wire is reactive, so an effect
             that reads it re-runs when the server changes it. A redirect after identifying was considered and
             rejected: it would have masked this one transition, left the staleness in place for switching
             operator, locking, unlocking and handover, and thrown away the basket and form state that 173
             deliberately preserves across the surface. --}}
        {{-- Prompt 187 defect 2: handed-over mode shipped with NO control of any kind, so aborting a handover
             was impossible — a staff member whose applicant walks away, gives up, or turns out to be underage
             had a terminal they could not recover without clearing the session. 173 required "the PIN is how
             you get back"; this is that PIN, kept behind one deliberate tap so the applicant is not invited
             to press it, but present, focusable and labelled rather than hidden behind a secret gesture. --}}
        {{-- Prompt 249 — a SUBMITTED handover opens the pad on arrival (the applicant has finished; the
             operator's next act IS the PIN) and titles it "Solicitud recibida". A handover still in progress
             keeps the tap-to-reveal above, so an applicant mid-form is never handed a PIN pad. Both are server
             facts, read once at init like every other seed here. --}}
        staffPad: @js(\App\Support\CounterHandover::submitted()),
        submitted: @js(\App\Support\CounterHandover::submitted()),
        get keysLocked() { return this.pinBusy() || $wire.pinLocked },
        {{-- Prompt 286 — the dots STAY filled while the PIN is checked, and nothing can be typed or submitted again
             until the answer is in; they clear on the answer (after the greeting, or with the shake). --}}
        submit() {
            if (this.pin === '' || this.keysLocked) return
            $wire.operatorPin = this.pin
            {{-- Prompt 281 — in the clock-out step the same pad confirms "Fichar salida" instead of signing in. --}}
            const clockOut = this.mode === 'clock' && $wire.clockPrompt === 'out'
            {{-- Prompt 338 — and «Fichar entrada» from the top bar, the same pad. --}}
            const clockIn = this.mode === 'clock' && $wire.clockPrompt === 'in-pin'
            {{-- The semicolon is load-bearing: Blade swallows the newline after an @js() directive. --}}
            this.successTemplate = clockOut ? @js(__('Salida fichada. Hasta luego, :name.')) : (clockIn ? @js(__('Entrada fichada, :name.')) : @js(__('Hola, :name')));
            this.checkPin(() => clockOut ? $wire.confirmClockOut() : (clockIn ? $wire.confirmClockIn() : $wire.unlockOperator())).then(() => { this.pin = '' })
        },
        get successText() { return this.successTemplate.replace(':name', this.greeting) },
        {{-- Prompt 272 — the keyboard. Enter used to be bound on window as "submit": but Enter is how a focused
             <button> is activated, and keydown reaches window before the button's click — so Enter on the
             second digit key submitted the ONE digit typed so far ("PIN no reconocido"), each key after the
             first costing an attempt against the lockout throttle (235). Enter now submits only when focus is
             NOT on one of this surface's own buttons (those activate themselves, the confirm included), and a
             physical keyboard or keypad types digits and Backspace straight into the pad. --}}
        onKey(e) {
            if (! this.open || ! this.padVisible || e.ctrlKey || e.metaKey || e.altKey) return
            if (e.key === 'Enter') {
                if (e.target?.closest?.('[data-counter-surface] button')) return
                e.preventDefault(); this.submit(); return
            }
            if (/^[0-9]$/.test(e.key)) { e.preventDefault(); this.push(e.key); return }
            if (e.key === 'Backspace') { e.preventDefault(); this.back() }
        },
        {{-- Initial focus in, focus back out (WCAG 2.4.3). NOT a focus trap — the recorded no-trap decision
             stands (DECISIONS: the audit's deferred trap); this only stops focus being left behind an
             aria-modal surface, where a screen reader treats the focused element as hidden. --}}
        returnFocusTo: null,
        focusChanged(isOpen) {
            if (isOpen) {
                this.returnFocusTo = document.activeElement
                setTimeout(() => this.$refs.surface?.focus({ preventScroll: true }), 0)
            } else if (this.returnFocusTo?.isConnected) {
                this.returnFocusTo.focus({ preventScroll: true })
                this.returnFocusTo = null
            }
        },
        {{-- Handed over outranks everything: the applicant must not be shown a lock screen mid-form.
             Otherwise a client-side idle lock outranks the server's 'no operator yet'. --}}
        get mode() {
            if ($wire.surfaceModeState === 'handover') return 'handover'
            {{-- Prompt 281 — the clock question/step arrives as the server mode 'clock'; the unlock that raises it
                 also lifts the client lock in the same response, so the order here is unchanged. --}}
            if ($store.counter.locked) return 'locked'
            return $wire.surfaceModeState ?? null
        },
        {{-- Checking or greeting holds the surface up even when the server's answer has already closed it. --}}
        get open() { return this.pinBusy() || this.mode !== null },
        {{-- The pad is the same pad in all three modes; only what it says differs. --}}
        get padVisible() { return this.pinBusy() || this.mode === 'locked' || this.mode === 'unidentified' || (this.mode === 'handover' && this.staffPad) || (this.mode === 'clock' && ['out', 'in-pin'].includes($wire.clockPrompt)) },
    }"
    x-effect="if (mode !== 'handover') staffPad = false"
    x-init="$watch('open', (v) => focusChanged(v)); if (open) focusChanged(true)"
    x-show="open"
    x-ref="surface"
    tabindex="-1"
    x-on:counter-unlocked.window="$store.counter.unlocked()"
    @keydown.window="onKey($event)"
    class="fixed inset-0 z-50 flex items-center justify-center bg-surface-alt p-4 focus:outline-none dark:bg-slate-950"
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
    {{-- Prompt 245 — `x-show`, never `x-if`, inside a Livewire-morphed view. `x-if` INSERTS its clone as a
         sibling of the `<template>`; a Livewire morph of the surrounding server HTML (which holds only the
         `<template>`) does not own that inserted clone, so the old one survived a "Cambiar de persona" morph
         while the re-initialised `x-if` inserted a SECOND — two PIN cards side by side. `x-show` keeps the
         card in the server HTML exactly ONCE and only toggles its visibility, so a morph has nothing to
         duplicate. This is 188/223's family from Alpine's side; the guard is
         CounterViewsUseXShowNotXIfTest. --}}
    <div data-handover-surface x-show="mode === 'handover' && ! staffPad" x-cloak class="flex w-full max-w-md flex-col items-center text-center">
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
                class="mt-10 min-h-[2.75rem] rounded-lg px-4 text-xs font-medium text-ink-muted underline underline-offset-4 transition hover:text-ink dark:text-slate-400 dark:hover:text-slate-300"
            >{{ __('Personal del club') }}</button>
        </div>

    {{-- Prompt 281 (Ben's 280) — the registro de jornada question, after a PIN from somebody with NO open period. Never on
         an idle-lock unlock of an open period (one step — asked every few minutes it would be tapped through blindly),
         never after a handover, never for the supervisor PIN. Clocking in is NOT a precondition for working. --}}
    @if ($clockPrompt === 'in')
        <div data-clock-in-question x-show="mode === 'clock' && ! pinBusy()" x-cloak class="w-full max-w-sm rounded-2xl border border-line bg-surface p-6 text-center shadow-xl dark:border-slate-800 dark:bg-slate-900">
            <h2 class="text-base font-semibold">{{ __('¿Fichas la entrada?') }}</h2>
            <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">{{ __('Hola, :name. Registra el inicio de tu jornada aquí.', ['name' => $this->currentOperatorName() ?? '']) }}</p>
            @if ($clockFeedback !== null)
                <p role="alert" class="mt-3 rounded-lg bg-error/10 px-3 py-2 text-sm font-medium text-error">{{ $clockFeedback }}</p>
            @endif
            <x-button type="button" wire:click="clockInNow" data-clock-in class="mt-4 min-h-[2.75rem] w-full">{{ __('Fichar entrada') }}</x-button>
            <x-button type="button" variant="secondary" wire:click="skipClockIn" data-clock-skip class="mt-2 min-h-[2.75rem] w-full">{{ __('Solo identificarme') }}</x-button>
        </div>
    @elseif ($clockPrompt === 'declare')
        @php($openPeriod = \App\Support\WorkedHours::openPeriodFor(\App\Support\CounterOperator::current() ?? new \App\Models\User))
        @if ($openPeriod !== null)
            @php($tz = $openPeriod->location->timezone ?: 'Europe/Madrid')
            <div data-clock-declare x-show="mode === 'clock' && ! pinBusy()" x-cloak class="w-full max-w-sm rounded-2xl border border-line bg-surface p-6 shadow-xl dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-base font-semibold">{{ __('Tu jornada sigue abierta') }}</h2>
                <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">{{ __('Tu jornada del :date en :sede sigue abierta. ¿A qué hora terminaste?', ['date' => $openPeriod->business_date->translatedFormat('l j'), 'sede' => $openPeriod->location->name]) }}</p>
                <label for="declared-end" class="mt-4 block text-sm font-medium">{{ __('Hora de salida') }}</label>
                <input id="declared-end" type="datetime-local" wire:model="declaredEnd"
                       min="{{ $openPeriod->occurred_at->setTimezone($tz)->format('Y-m-d\TH:i') }}" max="{{ now($tz)->format('Y-m-d\TH:i') }}"
                       class="mt-1 block min-h-[2.75rem] w-full rounded-lg border border-line bg-surface px-3 dark:border-slate-700 dark:bg-slate-950">
                <label for="declared-reason" class="mt-3 block text-sm font-medium">{{ __('Motivo') }}</label>
                <input id="declared-reason" type="text" wire:model="declaredReason" maxlength="200" placeholder="{{ __('p. ej. me olvidé de fichar') }}"
                       class="mt-1 block min-h-[2.75rem] w-full rounded-lg border border-line bg-surface px-3 dark:border-slate-700 dark:bg-slate-950">
                @if ($clockFeedback !== null)
                    <p data-clock-feedback role="alert" class="mt-3 rounded-lg bg-error/10 px-3 py-2 text-sm font-medium text-error">{{ $clockFeedback }}</p>
                @endif
                <x-button type="button" wire:click="declareForgottenEnd" data-clock-declare-submit class="mt-4 min-h-[2.75rem] w-full">{{ __('Guardar y fichar entrada') }}</x-button>
            </div>
        @endif
    @endif

    {{-- LOCKED, UNIDENTIFIED, and HANDED-OVER-with-the-staff-pad-open — the same PIN pad, the same
         UnlockOperator call and therefore the same throttle, differing only in what it says. --}}
    <div x-show="padVisible" x-cloak data-pin-pad
         x-bind:data-pin-state="checking ? 'checking' : (holding ? 'success' : (shaking ? 'error' : 'ready'))"
         x-bind:class="{ 'pin-shake': shaking, '!border-success': holding }"
         class="w-full max-w-xs rounded-2xl border border-line bg-surface p-6 shadow-xl dark:border-slate-800 dark:bg-slate-900">
            <div class="flex flex-col items-center text-center">
                {{-- Prompt 286 — a correct PIN turns the pad green with a check mark and the person's first name. --}}
                <span x-show="holding" x-cloak class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-success/10 text-success">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" class="h-7 w-7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                </span>
                <span x-show="! holding" class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-tint text-brand dark:bg-slate-800">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-6 w-6" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 0h10.5a2.25 2.25 0 0 1 2.25 2.25v6.75a2.25 2.25 0 0 1-2.25 2.25H6.75a2.25 2.25 0 0 1-2.25-2.25v-6.75a2.25 2.25 0 0 1 2.25-2.25Z"/>
                    </svg>
                </span>
                <h2 data-surface-heading class="mt-3 text-base font-semibold" x-bind:class="holding && 'text-success'"
                    x-text="holding ? successText : mode === 'clock' ? @js(__('Fichar salida')) : (mode === 'handover' ? (submitted ? @js(__('Solicitud recibida')) : @js(__('Recuperar el mostrador'))) : (mode === 'locked' ? @js(__('Pantalla bloqueada')) : @js(__('¿Quién está trabajando?'))))"></h2>
                <p x-show="! holding" class="mt-1 text-sm text-ink-muted dark:text-slate-400"
                   x-text="mode === 'clock' ? @js(__('Confirma tu salida con tu PIN.')) : (mode === 'handover' ? @js(__('Introduce tu PIN para finalizar la entrega y volver al mostrador.')) : (mode === 'locked' ? @js(__('Introduce tu PIN para continuar. El trabajo en curso se conserva.')) : @js(__('Introduce tu PIN para identificarte en el mostrador.'))))"></p>
            </div>

            <x-counter.pin-keys>
                {{-- Checking and success are announced as a status; a wrong PIN stays the server's role="alert" line below. --}}
                <p data-pin-status role="status" class="sr-only" x-text="checking ? @js(__('Comprobando…')) : (holding ? successText : '')"></p>

                @if (in_array($clockPrompt, ['out', 'in-pin'], true) && $clockFeedback !== null)
                    <p data-clock-feedback role="alert" class="mt-3 rounded-lg bg-error/10 px-3 py-2 text-center text-sm font-medium text-error">{{ $clockFeedback }}</p>
                @endif

                @if ($operatorFeedback !== null)
                    <p data-counter-surface-feedback role="alert" class="mt-3 rounded-lg bg-error/10 px-3 py-2 text-center text-sm font-medium text-error">{{ $operatorFeedback }}</p>
                @endif

                @if ($this->operatorLockedOut())
                    {{-- Prompt 286 — the keys stay disabled (the same state as checking) until the lockout ends; then the pad
                         asks the server once, which clears `pinLocked` and gives the keys back. --}}
                    <p data-pin-lockout x-init="setTimeout(() => $wire.$refresh(), {{ ($this->operatorLockoutSeconds() + 1) * 1000 }})"
                       class="mt-3 text-center text-sm text-ink-muted dark:text-slate-400">{{ __('Demasiados intentos. Inténtalo en :s s.', ['s' => $this->operatorLockoutSeconds()]) }}</p>
                    {{-- Where the key is (prompt 235): the wait is not the only way out any more, and the person
                         staring at this countdown is the one who most needs to know that. --}}
                    <p data-lockout-hint class="mt-1 text-center text-xs text-ink-muted dark:text-slate-400">{{ __('Un responsable puede desbloquearlo desde Administración › Seguridad.') }}</p>
                @endif
            </x-counter.pin-keys>

            <button type="button" data-counter-surface-unlock x-ref="pinPad" @click="submit()" x-bind:disabled="keysLocked" x-bind:aria-busy="checking"
                    class="mt-4 inline-flex min-h-[2.75rem] h-12 w-full items-center justify-center gap-2 rounded-lg bg-brand text-sm font-semibold text-white transition hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-70">
                <svg x-show="checking" x-cloak class="h-4 w-4 motion-safe:animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                <span x-text="checking ? @js(__('Comprobando…')) : holding ? successText : (mode === 'clock' ? ($wire.clockPrompt === 'in-pin' ? @js(__('Fichar entrada')) : @js(__('Fichar salida'))) : (mode === 'handover' ? @js(__('Recuperar el mostrador')) : (mode === 'locked' ? @js(__('Desbloquear')) : @js(__('Identificarse')))))"></span>
            </button>

            @if (in_array($clockPrompt, ['out', 'in-pin'], true))
                <button type="button" data-clock-out-cancel wire:click="cancelClockOut" class="mt-3 min-h-[2.75rem] w-full rounded-lg px-4 text-sm font-medium text-ink-muted transition hover:text-ink dark:text-slate-400 dark:hover:text-slate-300">{{ __('Cancelar') }}</button>
            @endif

            {{-- Opened by mistake, or the staff member changed their mind: hand the tablet back to the
                 applicant rather than leaving them facing a PIN pad. Handover mode only — and NOT once the
                 form is submitted (prompt 249): there is no applicant screen left to return to. --}}
            {{-- Prompt 289 — on a registered counter the lock surface is the front door; the password way in stays one tap away
                 (an owner wanting the full panel, or recovery). --}}
            @if (\App\Support\CounterTerminals::current() !== null && ! auth()->check())
                <a href="{{ url('/login?password=1') }}" data-terminal-password-login
                   class="mt-3 inline-flex min-h-[2.75rem] w-full items-center justify-center rounded-lg px-4 text-xs font-medium text-ink-muted transition hover:text-ink dark:text-slate-400 dark:hover:text-slate-300">{{ __('Entrar con contraseña') }}</a>
            @endif

            @unless (\App\Support\CounterHandover::submitted())
                <button
                    type="button"
                    data-handover-staff-cancel
                    x-show="mode === 'handover'"
                    x-cloak
                    @click="staffPad = false; clear()"
                    class="mt-3 min-h-[2.75rem] w-full rounded-lg px-4 text-xs font-medium text-ink-muted transition hover:text-ink dark:text-slate-400 dark:hover:text-slate-300"
                >{{ __('Volver a la pantalla del solicitante') }}</button>
            @endunless
        </div>
</div>

{{-- Prompt 289 — register this tablet as a counter, or forget it. A sheet inside the page; the operator's own PIN confirms. --}}
@if ($terminalDialog !== null)
    @php($thisTerminal = \App\Support\CounterTerminals::current())
    <div data-terminal-dialog role="dialog" aria-modal="true" aria-label="{{ __('Este dispositivo') }}"
         x-data="historyDialog('terminalDialog', () => $wire.cancelTerminal())"
         class="fixed inset-0 z-50 flex items-end justify-center bg-ink/40 p-0 sm:items-center sm:p-4">
        <div class="max-h-[90svh] w-full max-w-md overflow-y-auto rounded-t-2xl border border-line bg-surface p-5 shadow-xl dark:border-slate-800 dark:bg-slate-900 sm:rounded-2xl">
            @if ($terminalDialog === 'forget' && $thisTerminal !== null)
                <h2 class="text-base font-semibold">{{ __('Este dispositivo: :name · :sede', ['name' => $thisTerminal->name, 'sede' => $thisTerminal->location?->name]) }}</h2>
                <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">{{ __('Olvidarlo deja de abrir el mostrador sin contraseña. Confirma con tu PIN.') }}</p>
            @else
                <h2 class="text-base font-semibold">{{ __('Registrar este dispositivo como mostrador') }}</h2>
                <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">{{ __('Abrirá siempre el mostrador con el teclado de PIN, sin pedir contraseña. Cada acción sigue necesitando el PIN de quien trabaja.') }}</p>
                <label for="terminal-name" class="mt-4 block text-sm font-medium">{{ __('Nombre') }}</label>
                <input id="terminal-name" type="text" wire:model="terminalName" maxlength="40" placeholder="{{ __('p. ej. Tablet barra') }}"
                       class="mt-1 block min-h-[2.75rem] w-full rounded-lg border border-line bg-surface px-3 dark:border-slate-700 dark:bg-slate-950">
                <label for="terminal-sede" class="mt-3 block text-sm font-medium">{{ __('Sede') }}</label>
                <select id="terminal-sede" wire:model="terminalLocationId" class="mt-1 block min-h-[2.75rem] w-full rounded-lg border border-line bg-surface px-3 dark:border-slate-700 dark:bg-slate-950">
                    @foreach ($this->terminalSedeOptions() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            @endif
            <label for="terminal-pin" class="mt-3 block text-sm font-medium">{{ __('Tu PIN') }}</label>
            <input id="terminal-pin" type="password" inputmode="numeric" autocomplete="off" wire:model="terminalPin" maxlength="8"
                   class="mt-1 block min-h-[2.75rem] w-full rounded-lg border border-line bg-surface px-3 tracking-[0.4em] dark:border-slate-700 dark:bg-slate-950">
            @if ($terminalFeedback !== null)
                <p data-terminal-feedback role="alert" class="mt-3 rounded-lg bg-error/10 px-3 py-2 text-sm font-medium text-error">{{ $terminalFeedback }}</p>
            @endif
            <div class="mt-4 flex gap-2">
                <x-button type="button" variant="secondary" wire:click="cancelTerminal" class="min-h-[2.75rem] flex-1">{{ __('Cancelar') }}</x-button>
                @if ($terminalDialog === 'forget')
                    <x-button type="button" variant="danger" wire:click="confirmForgetTerminal" data-terminal-forget class="min-h-[2.75rem] flex-1">{{ __('Olvidar este dispositivo') }}</x-button>
                @else
                    <x-button type="button" wire:click="confirmRegisterTerminal" data-terminal-register class="min-h-[2.75rem] flex-1">{{ __('Registrar') }}</x-button>
                @endif
            </div>
        </div>
    </div>
@endif

{{-- Prompt 281 — "Mis horas": the signed-in person's OWN hours, this week and this month. Read-only, no permission needed,
     never anyone else's. A sheet inside the page (nothing leaves the tab); the back gesture closes it. --}}
@if ($myHoursOpen)
    @php($mine = $this->myHours())
    <div data-my-hours role="dialog" aria-modal="true" aria-label="{{ __('Mis horas') }}"
         x-data="historyDialog('myHours', () => $wire.closeMyHours())"
         class="fixed inset-0 z-50 flex items-end justify-center bg-ink/40 p-0 sm:items-center sm:p-4">
        <div class="max-h-[90svh] w-full max-w-lg overflow-y-auto rounded-t-2xl border border-line bg-surface p-5 shadow-xl dark:border-slate-800 dark:bg-slate-900 sm:rounded-2xl">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-base font-semibold">{{ __('Mis horas') }}</h2>
                <button type="button" wire:click="closeMyHours" class="inline-flex min-h-[2.75rem] items-center rounded-lg px-3 text-sm font-medium text-ink-muted hover:text-ink dark:text-slate-400">{{ __('Cerrar') }}</button>
            </div>
            <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">
                {{ __('Esta semana: :week · Este mes: :month', ['week' => sprintf('%d h %02d min', intdiv($mine['week_minutes'], 60), $mine['week_minutes'] % 60), 'month' => sprintf('%d h %02d min', intdiv($mine['month_minutes'], 60), $mine['month_minutes'] % 60)]) }}
            </p>
            {{-- Prompt 341 — today, first: how long so far, and since when. --}}
            @if ($mine['today_since'] !== null)
                <p data-my-hours-today class="mt-1 text-sm font-semibold text-ink dark:text-slate-100">{{ __('Hoy: :hours, desde :time', ['hours' => sprintf('%d h %02d min', intdiv($mine['today_minutes'], 60), $mine['today_minutes'] % 60), 'time' => $mine['today_since']]) }}</p>
            @endif
            <ul class="mt-3 divide-y divide-line dark:divide-slate-800">
                @forelse ($mine['periods'] as $p)
                    <li data-my-hours-row class="flex items-center justify-between gap-3 py-2 text-sm">
                        <span class="min-w-0">
                            <span class="font-medium">{{ \Carbon\CarbonImmutable::parse($p['business_date'])->translatedFormat('D j M') }}</span>
                            <span class="text-ink-muted dark:text-slate-400">· {{ $p['in']->location->name }}</span>
                            @foreach ($p['flags'] as $flag)
                                <span class="ml-1 rounded-full border border-warning/40 px-1.5 text-[11px] font-semibold text-warning">{{ $flag }}</span>
                            @endforeach
                        </span>
                        <span class="shrink-0 tabular-nums">
                            {{ local_datetime($p['in']->occurred_at, 'H:i', $p['in']->location) }}–{{ $p['out'] ? local_datetime($p['out']->occurred_at, 'H:i', $p['in']->location) : '…' }}
                            @if ($p['minutes'] !== null)<span class="text-ink-muted dark:text-slate-400">· {{ sprintf('%d:%02d', intdiv($p['minutes'], 60), $p['minutes'] % 60) }}</span>@endif
                        </span>
                    </li>
                @empty
                    <li class="py-6 text-center text-sm text-ink-muted dark:text-slate-400">{{ __('Todavía no has fichado este mes.') }}</li>
                @endforelse
            </ul>
        </div>
    </div>
@endif

