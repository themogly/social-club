{{-- THE sign-up surface (prompt 221) — the owner's Claude Design file, built.

     It replaces the inline panel prompt 210 put in the left column. His brief to the design tool was that
     the sign-up "is too small and still has the collect on the side… make it more user friendly", and both
     halves are answered by the same move: taking the sign-up off the page gives fee collection the whole
     screen, and gives the sign-up a surface big enough to ask for sixteen fields without a scroll war.

     **What is the design's, and what is not.** The chooser, the modal shell, the four-step stepper and the
     sticky footer are the design's, reproduced through the project's tokens rather than its inline hexes.
     Two things are deliberately NOT the design's:

       · Card 2 calls the REAL `handOverForAlta()` (prompt 174/173). The design invented a handover mode
         inside its own wizard — a banner and a fake form — because it could not see the real one. The owner's
         instruction was explicit: do not build that.
       · Step 4 is prompt 220, not the design's sketch of it. The design drew one combined consent tick over
         a bare canvas; the real step is the shared pad, the real consent semantics and the
         `signature_on_application` setting.

     **No focus trap, deliberately.** The a11y audit considered and REJECTED trapping focus on counter
     overlays: the failure mode of `inert` left on is a counter that looks fine and responds to nothing, and
     this component has already produced two such bugs. Recorded there, followed here. Escape, ✕ and the
     backdrop all close, and every write behind this surface is refused server-side by `requireOperator()`
     regardless — the modal is presentation, not the security boundary.

     `z-40`: BELOW the counter surface (prompt 173, `z-50`). A lock, an idle timeout or a handover must cover
     this, never the other way round — an applicant holding the tablet must not be looking at a staff form. --}}
@php
    $signupDirty = $this->altaHasEnteredData();
    $altaReviewing = $this->altaApplication();
    $altaSteps = $this->altaStepLabels();
    $altaLastStep = $this->lastAltaStep();
    $altaSubtitle = match (true) {
        $altaReviewing !== null => __('Revisa la solicitud y elige la cuota.'),
        $altaStaffFormOpen => __('Paso :n de :total · :name', ['n' => $altaStep, 'total' => $altaLastStep, 'name' => $altaSteps[$altaStep] ?? '']),
        default => __('¿Cómo vais a rellenar la solicitud?'),
    };
@endphp

{{-- THE close guard (prompt 222), and why it is not a `wire:confirm`.

     221 rendered the confirm as a SERVER-DECIDED conditional attribute over a server flag
     (`altaHasEnteredData()`), and the wizard's fields are deferred `wire:model` — they reach the server on
     the next ACTION, not on input. So the flag went true only after a round-trip, and the attribute appeared
     one render after that. Measured on `8797ee2`: typing a name and pressing Escape closed the modal with no
     confirm and lost it; the same typing followed by *Siguiente* then Escape confirmed correctly. The guard
     worked across steps and never within one — leaving the longest, most-typed step unprotected, which is the
     opposite of what it was for.

     No server can fix that: the current step's typing exists only in the DOM. So dirty is decided HERE, at
     close time, from the DOM — **or** the server flag, which is what knows about earlier steps after a
     re-render and about state that is not an input (a saved signature). Making every field `.live` was
     considered and rejected: a round-trip per keystroke on a counter tablet, for a form whose whole point
     was to stop feeling slow.

     ONE method, three routes. ✕, the backdrop and Escape all call `attemptClose()`; a fourth way out added
     later cannot skip the guard without deliberately not using it. --}}
<div
    data-alta-modal
    x-data="{
        {{-- Read at CLICK time, never snapshotted into state: Livewire preserves this DOM across re-renders,
             so anything captured at init goes stale the moment the server's view changes (prompt 188). --}}
        serverSaysDirty() {
            return $el.querySelector('[data-alta-panel]')?.dataset.altaDirty === '1'
        },

        {{-- Anything the operator has put into this modal that has not reached the server yet. --}}
        domSaysDirty() {
            const fields = $el.querySelectorAll('[data-alta-panel] input, [data-alta-panel] select, [data-alta-panel] textarea')

            for (const field of fields) {
                if (field.type === 'checkbox' || field.type === 'radio') {
                    if (field.checked) return true
                } else if (field.type === 'file') {
                    if (field.files?.length) return true
                } else if ((field.value ?? '').trim() !== '') {
                    return true
                }
            }

            {{-- A signature drawn but not yet saved is the sharpest case: it is not an input, it never
                 reaches the server until Guardar firma, and it is the one thing on this form the member
                 themselves did. The pad marks its canvas on the first stroke. --}}
            return !! $el.querySelector('[data-signature-canvas][data-drawn=\'1\']')
        },

        attemptClose() {
            if ((this.serverSaysDirty() || this.domSaysDirty()) && ! window.confirm(@js(__('¿Descartar esta alta? Se perderá lo que has escrito.')))) {
                return
            }

            $wire.closeAlta()
        },
    }"
    @keydown.escape.window="attemptClose()"
    class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-slate-950/60 p-4 backdrop-blur-sm sm:items-center"
    role="dialog"
    aria-modal="true"
    aria-labelledby="alta-modal-title"
>
    {{-- The backdrop is its own layer so a click on it closes, while a click INSIDE the panel does not.
         Through the same guard as ✕ and Escape: losing a half-typed application to a stray tap beside the
         panel is the failure mode of every modal, and the one the operator would never see coming. --}}
    <button
        type="button"
        data-alta-backdrop
        @click="attemptClose()"
        tabindex="-1"
        aria-hidden="true"
        class="absolute inset-0 h-full w-full cursor-default"
    ></button>

    {{-- The SERVER's half of the answer, published where the client guard can read it after every render:
         earlier steps that have already synced, and state that is not an input at all. --}}
    <div data-alta-panel data-alta-dirty="{{ $signupDirty ? '1' : '0' }}" class="counter-modal-pop relative my-auto flex max-h-[min(780px,92vh)] w-[min(720px,100%)] flex-col overflow-hidden rounded-[18px] border border-line bg-surface shadow-2xl dark:border-slate-700 dark:bg-slate-900">

        {{-- Header — the title is fixed, the subtitle says where you are. --}}
        <div class="flex items-start justify-between gap-4 border-b border-line px-5 py-4 dark:border-slate-800">
            <div class="min-w-0">
                <h2 id="alta-modal-title" class="text-lg font-semibold">{{ __('Alta de socio/a') }}</h2>
                <p data-alta-modal-subtitle class="mt-0.5 text-sm text-ink-muted dark:text-slate-400">{{ $altaSubtitle }}</p>
            </div>
            <button
                type="button"
                data-alta-close
                @click="attemptClose()"
                aria-label="{{ __('Cerrar') }}"
                class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-xl text-ink-muted transition hover:bg-surface-alt dark:text-slate-400 dark:hover:bg-slate-800"
            >&times;</button>
        </div>

        {{-- ============ THE WIZARD ============ --}}
        @if ($altaStaffFormOpen && $altaReviewing === null)
            {{-- The stepper. Numbered circles joined by progress bars, each circle a real 44px control: you
                 may tap back to a step you have filled in, and forward stays behind Siguiente, where the
                 step's own rules run. A stepper that jumped forward would be a way around validation. --}}
            <ol data-alta-stepper class="flex items-center gap-1 border-b border-line px-5 py-3 dark:border-slate-800">
                @foreach ($altaSteps as $n => $name)
                    <li @class(['flex items-center gap-1', 'flex-1' => $n < count($altaSteps)])>
                        <button
                            type="button"
                            wire:click="goToAltaStep({{ $n }})"
                            data-alta-step="{{ $n }}"
                            @disabled($n >= $altaStep)
                            @if ($n === $altaStep) aria-current="step" @endif
                            class="group inline-flex min-h-11 items-center gap-2 rounded-xl px-1.5 disabled:cursor-default"
                        >
                            <span @class([
                                'inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold transition',
                                'bg-brand text-white' => $n <= $altaStep,
                                'bg-surface-alt text-ink-muted dark:bg-slate-800 dark:text-slate-400' => $n > $altaStep,
                            ])>{{ $n < $altaStep ? '✓' : $n }}</span>
                            <span @class([
                                'hidden text-sm sm:inline',
                                'font-semibold text-ink dark:text-slate-100' => $n === $altaStep,
                                'text-ink-muted dark:text-slate-400' => $n !== $altaStep,
                            ])>{{ $name }}</span>
                        </button>
                        @if ($n < count($altaSteps))
                            <span aria-hidden="true" @class([
                                'h-1 flex-1 rounded-full',
                                'bg-brand' => $n < $altaStep,
                                'bg-line dark:bg-slate-800' => $n >= $altaStep,
                            ])></span>
                        @endif
                    </li>
                @endforeach
            </ol>

            <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                @include('livewire.counter.partials.alta-staff-form')
            </div>

            {{-- Sticky footer. On a portrait tablet the body is the part that scrolls; the way forward never
                 leaves the screen — which is the exact defect prompt 173 fixed on the till. --}}
            <div class="flex items-center justify-between gap-3 border-t border-line bg-surface px-5 py-4 dark:border-slate-800 dark:bg-slate-900">
                <button
                    type="button"
                    wire:click="altaBack"
                    data-alta-back
                    class="inline-flex min-h-11 items-center rounded-xl border border-line px-4 text-sm font-semibold text-ink-muted transition hover:bg-surface-alt dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800"
                >{{-- The arrow is decoration, not copy: keeping it out of the key stops a translator being
                       handed a glyph to translate, and stops the same word existing twice. --}}
                    <span aria-hidden="true" class="mr-1.5">←</span>{{ $altaStep === 1 ? __('Métodos') : __('Atrás') }}</button>

                @if ($altaStep < $altaLastStep)
                    <x-button wire:click="altaNext" data-alta-next size="md">{{ __('Siguiente') }}</x-button>
                @else
                    <x-button wire:click="submitStaffAlta" data-alta-staff-submit size="md" wire:loading.attr="disabled" wire:target="submitStaffAlta">{{ __('Guardar socio/a') }}</x-button>
                @endif
            </div>

        {{-- ============ REVIEWING ONE THAT HAS COME BACK ============ --}}
        @elseif ($altaReviewing !== null)
            @php $payload = $altaReviewing->payload ?? []; @endphp
            <div data-alta-review class="min-h-0 flex-1 space-y-3 overflow-y-auto px-5 py-4">
                <div class="rounded-xl bg-surface-alt p-3 text-sm dark:bg-slate-800">
                    <p class="font-semibold">{{ trim(($payload['first_name'] ?? '').' '.($payload['last_name'] ?? '')) ?: __('Solicitud sin nombre') }}</p>
                    <p class="text-ink-muted dark:text-slate-400">{{ $payload['email'] ?? '—' }}</p>
                    <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">
                        {{ __('Documento') }}: {{ $payload['document_type'] ?? '—' }} ·
                        {{ __('Nacimiento') }}: {{ $payload['date_of_birth'] ?? '—' }}
                    </p>
                </div>

                <div>
                    <label for="alta-tier" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Cuota / tier') }}</label>
                    <select id="alta-tier" wire:model="altaTierId" class="mt-1.5 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                        <option value="">{{ __('Elige una cuota…') }}</option>
                        @foreach ($this->altaTiers() as $tier)
                            <option value="{{ $tier->id }}">{{ $tier->name }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- A duplicate is a DECISION, never a default. The matches are named so the staff member can
                     tell "this is the same person" from "same surname". --}}
                @if ($altaDuplicateBlocked)
                    <div data-alta-duplicates class="rounded-xl border border-warning/40 bg-warning/10 p-3 text-sm">
                        <p class="font-semibold text-warning">{{ __('Ya existe un socio que coincide') }}</p>
                        <ul class="mt-1 space-y-0.5">
                            @foreach ($this->altaDuplicateMatches() as $match)
                                <li class="text-ink dark:text-slate-200">· {{ $match->fullName() }} ({{ $match->member_no }})</li>
                            @endforeach
                        </ul>
                        <button
                            type="button"
                            wire:click="approveAlta(true)"
                            data-alta-override
                            wire:confirm="{{ __('¿Aprobar de todas formas? Quedará registrado que se creó pese a la coincidencia.') }}"
                            class="mt-3 inline-flex h-11 items-center rounded-xl border border-warning/50 px-4 text-sm font-semibold text-warning transition hover:bg-warning/10"
                        >{{ __('Es otra persona: dar de alta igualmente') }}</button>
                    </div>
                @endif
            </div>

            <div class="flex items-center justify-between gap-3 border-t border-line bg-surface px-5 py-4 dark:border-slate-800 dark:bg-slate-900">
                <button type="button" wire:click="cancelAltaReview" class="inline-flex min-h-11 items-center rounded-xl border border-line px-4 text-sm font-semibold text-ink-muted transition hover:bg-surface-alt dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800">{{ __('Cancelar') }}</button>
                <x-button wire:click="approveAlta" data-alta-approve size="md">{{ __('Aprobar y dar de alta') }}</x-button>
            </div>

        {{-- ============ THE METHOD CHOOSER ============

             ONE JOB, THREE WAYS (prompt 210). Each option says what CONSENT ARTEFACT it produces, because
             that is the one part that is not interchangeable: with signatures on all three end in the
             member's own hand, and with them off the staff route ends in the club's record of a paper
             consent. An operator choosing between these is choosing between those. --}}
        @else
            <div class="min-h-0 flex-1 space-y-3 overflow-y-auto px-5 py-4">
                <button type="button" wire:click="toggleStaffAltaForm" data-alta-staff-form
                        class="flex w-full items-center gap-4 rounded-2xl border border-line bg-surface p-4 text-left transition hover:border-brand hover:bg-brand-tint dark:border-slate-700 dark:bg-slate-900 dark:hover:bg-slate-800">
                    <span aria-hidden="true" class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-brand-tint text-2xl dark:bg-slate-800">📝</span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-base font-semibold">{{ __('Rellenar sus datos aquí') }}</span>
                        <span class="block text-sm text-ink-muted dark:text-slate-400">{{ __('Tú lo escribes y firma en pantalla al final.') }}</span>
                    </span>
                    <span aria-hidden="true" class="shrink-0 text-lg text-ink-muted dark:text-slate-400">→</span>
                </button>

                {{-- 173's REAL handover, not a mode inside this wizard: the applicant gets the actual public
                     form at the actual token, the counter's chrome leaves the DOM and the PIN is the way
                     back. Building a lookalike here would have been a second sign-up path to keep in step. --}}
                <button type="button" wire:click="handOverForAlta" data-alta-handover
                        class="flex w-full items-center gap-4 rounded-2xl border border-line bg-surface p-4 text-left transition hover:border-brand hover:bg-brand-tint dark:border-slate-700 dark:bg-slate-900 dark:hover:bg-slate-800">
                    <span aria-hidden="true" class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-brand-tint text-2xl dark:bg-slate-800">🤝</span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-base font-semibold">{{ __('Entregar la tablet') }}</span>
                        <span class="block text-sm text-ink-muted dark:text-slate-400">{{ __('Lo rellena la persona, acepta el consentimiento y firma.') }}</span>
                    </span>
                    <span aria-hidden="true" class="shrink-0 text-lg text-ink-muted dark:text-slate-400">→</span>
                </button>

                <div class="flex items-center gap-3 pt-1">
                    <span class="h-px flex-1 bg-line dark:bg-slate-800"></span>
                    <span class="text-xs font-medium uppercase tracking-wide text-ink-muted dark:text-slate-400">{{ __('o enviar una invitación por email') }}</span>
                    <span class="h-px flex-1 bg-line dark:bg-slate-800"></span>
                </div>

                @if ($altaInviteSent)
                    <p data-alta-invite-sent class="flex min-h-11 items-center gap-2 rounded-xl border border-success/30 bg-success/10 px-4 text-sm font-medium text-success">
                        ✓ {{ __('Invitación enviada. Puede darse de alta y firmar desde su móvil.') }}
                    </p>
                @else
                    <div class="flex gap-2">
                        <label for="alta-email" class="sr-only">{{ __('Email para la invitación') }}</label>
                        <input id="alta-email" type="email" inputmode="email" wire:model="altaInviteEmail" autocomplete="off" placeholder="socio@example.es"
                               class="h-12 min-w-0 flex-1 rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                        <button type="button" wire:click="sendAltaInvitation" data-alta-invite
                                class="inline-flex h-12 shrink-0 items-center rounded-xl border border-line px-4 text-sm font-semibold transition hover:bg-surface-alt dark:border-slate-700 dark:hover:bg-slate-800">{{ __('Enviar invitación') }}</button>
                    </div>
                @endif

                {{-- The applications that came back and are waiting to become members. They belong on this
                     surface rather than the screen behind it: they ARE sign-ups in progress, and 207's hub
                     alert opens straight onto them. --}}
                @php $pending = $this->pendingAltaApplications(); @endphp
                @if ($pending->isNotEmpty())
                    <div class="pt-2">
                        <p class="text-xs font-medium uppercase tracking-wide text-ink-muted dark:text-slate-400">{{ __('Solicitudes pendientes de revisar') }}</p>
                        <ul data-alta-pending class="mt-2 divide-y divide-line overflow-hidden rounded-xl border border-line dark:divide-slate-800 dark:border-slate-800">
                            @foreach ($pending as $application)
                                @php $p = $application->payload ?? []; @endphp
                                <li>
                                    <button type="button" wire:click="reviewAltaApplication('{{ $application->id }}')" class="flex min-h-11 w-full items-center justify-between gap-3 bg-surface px-4 py-3 text-left text-sm transition hover:bg-surface-alt dark:bg-slate-900 dark:hover:bg-slate-800">
                                        <span class="min-w-0 truncate">{{ trim(($p['first_name'] ?? '').' '.($p['last_name'] ?? '')) ?: ($application->applicant_email ?? __('Solicitud')) }}</span>
                                        <span class="shrink-0 text-xs text-ink-muted dark:text-slate-400">{{ $application->submitted_at?->format('d/m/Y') }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif
    </div>
</div>
