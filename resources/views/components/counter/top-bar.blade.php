@props(['title' => null])
@php
    // The SAME gate the sidebar uses (User::canAccessPanel): a fixed counter-only login
    // with no panel access sees no way into admin — that lockdown is intentional.
    $user = auth()->user();
    // Prompt 267 — the signed-in user IS the PIN person (the PIN is a sign-in); with nobody identified, no way into the panel.
    $canPanel = $user !== null && \App\Support\CounterOperator::id() !== null && $user->canAccessPanel(\Filament\Facades\Filament::getPanel('admin'));

    // TWO confirms, because there are two different losses (prompt 206).
    //   · leaving the counter  — the basket goes with the session; `counter.dirty` is the right question.
    //   · going home           — the basket SURVIVES (App\Support\CounterBasket), so the only thing a
    //                            navigation inside the counter can lose is typed-but-unsubmitted input,
    //                            which is `counter.volatile` and today means the till's count/movement
    //                            fields only.
    $confirmLeave = __('Tienes trabajo sin guardar en el mostrador. ¿Seguro que quieres salir?');
    $confirmDiscard = __('Hay datos sin guardar en esta pantalla. Se perderán. ¿Continuar?');

    // Prompt 239 — ending a SHIFT and logging the DEVICE out are different acts, and only the second is a
    // responsable's. A counter tablet is signed in ONCE (remember-me, see App\Filament\Pages\Auth\Login) and
    // floor staff then identify by PIN; "Cerrar sesión" in the always-visible row invited an operator to end
    // the device session — a full re-login floor staff cannot do — when all they wanted was to hand over to
    // the next person. So the DEVICE logout is gated on `staff.manage` and asks first; the operator's own
    // "end my turn" is "Cambiar de persona" (the switch chip below), which just clears the PIN.
    $canManageDevice = $user?->can('staff.manage') ?? false;
    $confirmDeviceLogout = __('¿Cerrar la sesión de este dispositivo? El personal se identifica con su PIN; para volver a entrar hará falta un responsable.');

    // Whose terminal this is (prompt 206). 205 left only the PRODUCT name's first letter in an aria-hidden
    // tile, so nothing on any counter screen said which club the staff were working at — and the product
    // name is the wrong name anyway (prompt 150 records the same mistake on club email). One indexed lookup.
    $clubName = \App\Support\OrganisationIdentity::tradingName();

    // Which sede this terminal is working at (prompt 89). Resolved HERE, in the one shared header, so all
    // four counter screens show it identically. Read from the counter's OWN state (session
    // `counter.location_id`) — never the admin panel scope, and switching goes through the validated
    // POST /counter/location route. A single-sede operator shows their only sede even before the component
    // has persisted the adoption; several sedes with none chosen ⇒ the operator must pick (never a guess).
    $availableSedes = \App\Support\CounterTerminals::availableSedes($user); // a registered counter's home sede before a PIN (289)
    $currentSedeId = session('counter.location_id');
    $currentSede = is_string($currentSedeId) ? $availableSedes->firstWhere('id', $currentSedeId) : null;
    if ($currentSede === null && $availableSedes->count() === 1) {
        $currentSede = $availableSedes->first();
    }
    $mustChooseSede = $currentSede === null && $availableSedes->count() > 1;

    // Prompt 246 — the device is the club's, the sede is the manager's, the PIN is the person's. The switcher
    // is always VISIBLE (89: the sede must always be on screen), but CHANGING it is a responsable's act: the
    // dropdown only opens when the CURRENT OPERATOR — the PIN-identified person, not the device account — can
    // manage this location (settings.manage.location — held by MANAGER+OWNER, not STAFF; staff.manage is OWNER-only here, and 246 wants MANAGER to switch too). A STAFF operator on an owner-logged tablet sees a static badge, not a way to move the whole
    // terminal to another sede by a PIN-less tap. `switch()` refuses on the operator server-side to match.
    // CHANGING an already-adopted sede is the responsable's act; the INITIAL adoption on a fresh terminal
    // (mustChooseSede — the sede→operator chain's first step, before any operator is identified) stays open,
    // or a multi-sede STAFF would deadlock with no way to start. Once a sede is set, a STAFF is locked to it.
    $operatorCanSwitchSede = $mustChooseSede
        || (\App\Support\CounterOperator::current()?->can('settings.manage.location') ?? false);
    $noSede = $availableSedes->isEmpty();
    $sedeSwitchError = session('counterLocationError');
    // Prompt 335 — leaving a sede whose till is open, for someone who may: asked here, confirmed by a second POST.
    $sedeSwitchConfirm = session('counterSedeSwitchConfirm');

    // Prompt 205: the destinations are NOT read here any more. The tab strip is gone — the hub is the menu,
    // and App\Support\CounterScreens is consumed by the hub's tiles. Every control now exists in exactly one
    // place, which is the whole of the owner's "just duplicate data" complaint.
@endphp

{{--
    The one shared counter header — **the terminal strip** (prompt 205).

    The owner: *"this dashboard doesn't work, you go to it and can't get back to it. Also it's just duplicate
    data."* Both checked out. The route home was a 44×44 brand-blue square with one letter in it and an
    `aria-label`; the words beside it that looked like they belonged to it were a separate, unclickable div.
    **The route home was a logo**, which is why it read as missing — so it is a LABELLED link now, and it is
    the most-used control in the product.

    And after prompt 189 nearly everything here was in two places: the five destinations were in this bar AND
    on the hub, and so were the sede, the working operator, Panel and Log out. 189's prompt said non-transaction
    operations belong on the home screen; they were added there and never removed from here.

    So the split is now clean, and it is the model the owner chose:
      · this bar   — Home, the sede, who is working (and Switch), Lock screen, Administración, Log out, panic
      · the hub    — the destinations, and the live panels
    The tab strip is gone. The overflow is gone. Nothing renders in both.

    **Prompt 206 — the bar now says what it does.** It carried two controls that both read "go to the main
    screen": *Inicio* (the counter hub) and *Panel*, which `lang/en.json` renders as **Dashboard** — so an
    English operator read *Home* and *Dashboard* side by side, two synonyms, neither naming the application it
    opens. **And the house icon was on the wrong one**: the admin link wore the home glyph while the control
    that actually goes home wore a letter. Each is now named by its DESTINATION — the club's own counter, and
    *Administración* — the house sits on the link that goes home, and the two controls that LEAVE the counter
    are grouped behind a divider away from the ones that stay inside it.

    Leaving the counter entirely still confirms unsaved work (`counter.dirty`); going home confirms only
    `counter.volatile` — typed input that no navigation preserves — because prompt 205 made the BASKET survive
    the trip, so a Home confirm about a lost basket was warning about a loss that cannot happen.
--}}
<header
    data-counter-topbar
    class="flex flex-wrap items-center justify-between gap-x-2 gap-y-2 border-b border-line px-4 py-3 dark:border-slate-800 sm:px-6 md:flex-nowrap"
>
    {{-- Prompt 272 — the row WRAPS rather than crushing or overflowing. At 390 the fixed right-hand group
         was wider than the phone, so the page scrolled sideways (scrollWidth 451), the home link was crushed
         to 16px and the panic control sat off screen. Wrapping moves the terminal controls to a second row
         (and, at phone width, the leave-group to a third) — every control stays ONE tap and ON screen, and
         nothing is hidden behind an overflow: Lock (198) and panic (121) are one-tap by decision, and the
         Administración word is visible at every width (246). --}}
    {{-- Prompt 341 — `contents` on a phone, so the sede can drop to its own row (order-last, full width) while the chip,
         the padlock and ⋯ stay up top; a row of its own from md. --}}
    <div class="contents min-w-0 md:flex md:items-center md:gap-3">
        {{-- HOME — a labelled link, not a logo (205), and now named by where it goes (206).

             205's fix was that the route home must not BE a logo: it was a 44×44 brand square with one letter
             and an `aria-label`, and the words beside it were a separate, unclickable `div`. That still holds —
             this is one control, and it is the only route between screens.

             What 206 changes is what it SAYS. "Inicio" and "Panel" (English: *Home* and *Dashboard*) were
             synonyms sitting a few pixels apart, and the **house glyph was on the admin link** — the visual
             language was inverted, which is most of why the owner read this row as confusing rather than
             merely ambiguous. So the house is here, on the control that goes home; the club's name is back,
             because it is the identity of the screen staff work at all day and 205 had reduced it to one
             aria-hidden letter of the PRODUCT name; and the destination is spelled out for assistive tech
             (visible text stays part of the accessible name, so WCAG 2.5.3 holds).

             The confirm: `volatile`, not `dirty` — see the top of this file. Going home cannot lose a basket. --}}
        <a href="{{ route('counter.home') }}"
           data-counter-home-link
           wire:navigate.ignore
           @click.prevent="(! ($store.counter?.volatile) || window.confirm(@js($confirmDiscard))) && window.location.assign('{{ route('counter.home') }}')"
           @class([
               'flex min-w-0 min-h-11 items-center gap-2 rounded-xl px-2 text-left transition sm:px-3',
               'bg-brand-tint text-brand dark:bg-slate-800 dark:text-white' => request()->routeIs('counter.home'),
               'text-ink hover:bg-brand-tint hover:text-brand dark:text-slate-100 dark:hover:bg-slate-800 dark:hover:text-white' => ! request()->routeIs('counter.home'),
           ])
           @if (request()->routeIs('counter.home')) aria-current="page" @endif>
            {{-- The house, taken back off the admin link where 205 left it. --}}
            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand text-white" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12 12 2.25 21.75 12M4.5 9.75v9.75a.75.75 0 0 0 .75.75H9.75V15.75a1.5 1.5 0 0 1 1.5-1.5h1.5a1.5 1.5 0 0 1 1.5 1.5v4.5h4.5a.75.75 0 0 0 .75-.75V9.75"/>
                </svg>
            </span>
            <span class="min-w-0 leading-tight">
                {{-- Whose terminal this is. Visible, so it is part of the link's accessible name. --}}
                {{-- Prompt 341 — on a phone the house alone (the name is still the link's accessible name), so the chip,
                     the padlock and ⋯ share the first row with it. --}}
                <span class="sr-only sm:not-sr-only sm:block sm:max-w-[9rem] sm:truncate sm:text-sm sm:font-semibold lg:max-w-[14rem]">{{ $clubName }}</span>
                {{-- The counter screen's one <h1> (a11y): the shared header renders it for every terminal,
                     so headings below can start at h2 without skipping a level. --}}
                <h1 class="sr-only sm:not-sr-only sm:truncate sm:text-xs sm:font-normal sm:text-ink-muted sm:dark:text-slate-400">{{ $title ?? __('Mostrador') }}</h1>
            </span>
            {{-- …and where the link GOES, which the club's name alone does not say. Same idiom as the
                 operator chip's "· Cambiar" below. --}}
            <span class="sr-only">· {{ __('Inicio del mostrador') }}</span>
        </a>

        {{-- Which sede this terminal is working at (prompt 89) — shown on EVERY counter screen, from the
             one shared header. Zero sedes: a warning. One sede: a static badge (nothing to switch to).
             Several: a switcher (each a validated POST to /counter/location, confirming unsaved work);
             several with none chosen yet ⇒ a highlighted "choose your sede" prompt, never a silent guess. --}}
        <div class="relative order-last shrink-0 basis-full md:order-none md:basis-auto" data-counter-sede-region>
            @if ($noSede)
                <span data-counter-sede-state="none"
                      class="inline-flex items-center gap-1.5 rounded-lg bg-warning/10 min-h-11 px-3 text-sm font-medium text-warning">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                    {{ __('Sin sede') }}
                </span>
            @elseif ($availableSedes->count() > 1 && ! $operatorCanSwitchSede)
                {{-- Prompt 246 — several sedes, but this operator may not change them: a static badge naming the
                     sede, and who does change it. The sede is always ON SCREEN (89); moving the terminal is a
                     responsable's act. Not a disabled dropdown — a control that cannot act should not look like
                     one it can. --}}
                <span data-counter-sede-current="{{ $currentSede?->id }}" data-counter-sede-locked
                      class="inline-flex items-center gap-1.5 rounded-lg bg-surface-alt min-h-11 px-3 text-sm font-medium text-ink dark:bg-slate-800 dark:text-slate-100"
                      title="{{ __('La sede la cambia un responsable') }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4 text-brand" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                    {{ $currentSede?->name ?? __('Sin sede') }}
                    <span class="sr-only">· {{ __('La sede la cambia un responsable') }}</span>
                </span>
            @elseif ($availableSedes->count() === 1)
                <span data-counter-sede-current="{{ $currentSede?->id }}"
                      class="inline-flex items-center gap-1.5 rounded-lg bg-surface-alt min-h-11 px-3 text-sm font-medium text-ink dark:bg-slate-800 dark:text-slate-100">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4 text-brand" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                    {{ $currentSede?->name }}
                </span>
            @else
                <div x-data="{ open: {{ $mustChooseSede ? 'true' : 'false' }} }">
                    <button type="button" @click="open = ! open"
                            data-counter-sede-current="{{ $currentSede?->id }}"
                            aria-haspopup="true" :aria-expanded="open.toString()"
                            @class([
                                'inline-flex items-center gap-1.5 rounded-lg min-h-11 px-3 text-sm font-medium transition',
                                'bg-warning/10 text-warning ring-1 ring-warning/50' => $mustChooseSede,
                                'bg-surface-alt text-ink hover:bg-brand-tint hover:text-brand dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700 dark:hover:text-white' => ! $mustChooseSede,
                            ])>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                        <span>{{ $mustChooseSede ? __('Elige tu sede') : $currentSede?->name }}</span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
                    </button>
                    <div x-show="open" x-cloak @click.outside="open = false" @keydown.escape.window="open = false"
                         data-counter-sede-menu
                         class="absolute left-0 z-30 mt-1 w-60 rounded-xl border border-line bg-surface p-1 shadow-lg dark:border-slate-800 dark:bg-slate-900">
                        @if ($mustChooseSede)
                            <p class="px-3 py-2 text-xs text-ink-muted dark:text-slate-400">{{ __('Selecciona la sede en la que trabajas.') }}</p>
                        @endif
                        @foreach ($availableSedes as $sede)
                            @php $isCurrent = $currentSede?->id === $sede->id; @endphp
                            <form method="POST" action="{{ route('counter.location') }}"
                                  @submit="($store.counter?.dirty && ! window.confirm(@js($confirmLeave))) && $event.preventDefault()">
                                @csrf
                                <input type="hidden" name="location_id" value="{{ $sede->id }}">
                                <button type="submit" data-counter-sede="{{ $sede->id }}"
                                        @class([
                                            'flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-left text-sm transition',
                                            'bg-brand-tint font-semibold text-brand dark:bg-slate-800 dark:text-white' => $isCurrent,
                                            'font-medium text-ink hover:bg-brand-tint hover:text-brand dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white' => ! $isCurrent,
                                        ])
                                        @if ($isCurrent) aria-current="true" @endif>
                                    <span>{{ $sede->name }}</span>
                                    @if ($isCurrent)
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                                    @endif
                                </button>
                            </form>
                        @endforeach
                    </div>
                </div>
            @endif

            @if (is_array($sedeSwitchConfirm))
                {{-- Prompt 335 — the old till stays exactly as it is (open, same float and movements); confirming only moves
                     the counter. Cancel closes this and nothing has changed. --}}
                <div x-data="{ open: true }" x-show="open" role="alertdialog" aria-labelledby="counter-sede-switch-title" data-counter-sede-switch-confirm
                     class="absolute left-0 top-full z-40 mt-1 w-[min(24rem,calc(100vw-2rem))] rounded-xl border border-warning/50 bg-surface p-4 shadow-lg dark:bg-slate-900">
                    <p id="counter-sede-switch-title" class="text-sm font-semibold text-ink dark:text-slate-100">{{ __('La caja de :sede sigue abierta.', ['sede' => $sedeSwitchConfirm['from'] ?? '']) }}</p>
                    <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">{{ __('Se quedará abierta; podrás volver a cerrarla más tarde. ¿Cambiar a :sede?', ['sede' => $sedeSwitchConfirm['to'] ?? '']) }}</p>
                    <form method="POST" action="{{ route('counter.location') }}" class="mt-3 flex justify-end gap-2"
                          @submit="($store.counter?.dirty && ! window.confirm(@js($confirmLeave))) && $event.preventDefault()">
                        @csrf
                        <input type="hidden" name="location_id" value="{{ $sedeSwitchConfirm['location_id'] ?? '' }}">
                        <input type="hidden" name="confirm_open_till" value="1">
                        <x-button type="button" size="sm" variant="secondary" x-on:click="open = false">{{ __('Cancelar') }}</x-button>
                        <x-button type="submit" size="sm" data-counter-sede-switch-go>{{ __('Cambiar de sede') }}</x-button>
                    </form>
                </div>
            @endif

            @if ($sedeSwitchError)
                <p role="alert" data-counter-sede-error
                   class="absolute left-0 top-full z-30 mt-1 w-max max-w-xs rounded-lg bg-error-fill px-2.5 py-1.5 text-xs font-medium text-white shadow">
                    {{ $sedeSwitchError }}
                </p>
            @endif
        </div>
    </div>

    {{-- The five-destination tab strip stood here and is GONE (prompt 205). The hub is the menu now: the
         destinations were in this bar AND on the hub after 189, which is the "duplicate data" the owner
         reported, and a strip that took prompts 116, 130 and 132 to fit on a portrait tablet was the more
         expensive of the two copies to keep. `CounterScreens` is unchanged and is read by the tiles. --}}

    {{-- ============ THE TERMINAL CONTROLS — prompt 341: ONE row at tablet widths ============
         Ben: "The menu, how it works, is a little messy. There's no way to see at the top if you're clocked in and
         when, and no button for you to clock in if you're not. And it's on 2 lines." Twelve icon controls did not fit
         one row at ~800 px. Now only what is used constantly stays in the bar — the bell (330), WHO is working with
         their CLOCK STATE (the chip), the padlock (198) and the lockdown shield (121: one tap plus confirm) — and the
         rest sits, labelled, in ⋯ Más. Every control keeps its behaviour, permission, confirmation and data hook. On a
         phone the sede drops to its own row so the chip, the padlock and ⋯ stay on the first. --}}
    @php
        $chipOperator = \App\Support\CounterOperator::current();
        $clock = $chipOperator !== null ? \App\Support\ClockState::for($chipOperator) : null;
        $practising = \App\Support\TrainingMode::active();
        $menuItem = 'flex min-h-11 w-full items-center gap-3 rounded-lg px-3 text-left text-sm font-medium text-ink transition hover:bg-brand-tint hover:text-brand dark:text-slate-100 dark:hover:bg-slate-800 dark:hover:text-white';
        $menuPanel = 'absolute right-0 top-full z-40 mt-1 w-64 rounded-xl border border-line bg-surface p-1 shadow-lg dark:border-slate-800 dark:bg-slate-900';
    @endphp
    {{-- `contents` on a phone (its controls join the header's own row, the amber «Fichar entrada» dropping below with the
         sede); its own right-aligned group from md. --}}
    <div class="contents md:ml-auto md:flex md:shrink-0 md:items-center md:gap-1">
        <span class="ml-auto md:hidden" aria-hidden="true"></span>
        @if ($user !== null && $chipOperator !== null)
            {{-- Prompt 330 — sign-ups awaiting review at this sede: the bell (hidden at zero) and the banner under this bar
                 (teleported to the layout's #counter-notices). Its own component with its own 15 s poll. --}}
            <livewire:counter.pending-applications-bell :key="'pending-applications-bell'" />

            {{-- THE CHIP — who is working and whether they are clocked in (ClockState: «Fichado 18:02» green, «Sin fichar»
                 amber, «Fichado ayer 23:40» amber). The chrome re-renders on `counter-clock-state`, so it changes the
                 moment a clock event lands. Tapping it opens the person's own actions; each clock act asks for THEIR PIN
                 (281 / ClockRules). The switch keeps 173's rule: one route to the pad, the surface's own. --}}
            <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false" @click.outside="open = false">
                <button type="button" data-operator-name-chip data-clock-state="{{ $clock['state'] }}"
                        @click="open = ! open" aria-haspopup="true" :aria-expanded="open.toString()"
                        title="{{ $chipOperator->name }} · {{ $clock['label'] }}"
                        @class([
                            'inline-flex min-h-11 max-w-[8.5rem] items-center gap-2 rounded-lg px-3 text-left text-sm transition hover:bg-brand-tint dark:hover:bg-slate-700 sm:max-w-[13rem]',
                            'bg-surface-alt dark:bg-slate-800' => $clock['state'] === 'in',
                            'bg-warning/10 ring-1 ring-warning/40' => $clock['state'] !== 'in',
                        ])>
                    <span @class(['inline-block h-2.5 w-2.5 shrink-0 rounded-full', 'bg-success' => $clock['state'] === 'in', 'bg-warning' => $clock['state'] !== 'in']) aria-hidden="true"></span>
                    <span class="min-w-0 leading-tight">
                        <span data-operator-name class="block truncate font-semibold">{{ $chipOperator->name }}</span>
                        <span data-clock-label @class(['block truncate text-xs', 'text-ink-muted dark:text-slate-400' => $clock['state'] === 'in', 'font-semibold text-warning' => $clock['state'] !== 'in'])>{{ $clock['label'] }}</span>
                    </span>
                </button>
                <div x-show="open" x-cloak data-counter-chip-menu class="{{ $menuPanel }}" role="menu">
                    @unless ($practising)
                        @if ($clock['state'] === 'out')
                            <button type="button" role="menuitem" data-counter-clock-in class="{{ $menuItem }}" @click="open = false; window.Livewire.dispatch('counter-clock-in')">
                                <x-counter.icon name="clock" class="h-5 w-5 shrink-0" />{{ __('Fichar entrada') }}
                            </button>
                        @else
                            <button type="button" role="menuitem" data-counter-clock-out class="{{ $menuItem }}" @click="open = false; window.Livewire.dispatch('counter-clock-out')">
                                <x-counter.icon name="clock" class="h-5 w-5 shrink-0" />{{ __('Fichar salida') }}
                            </button>
                        @endif
                    @endunless
                    <button type="button" role="menuitem" data-counter-my-hours class="{{ $menuItem }}" @click="open = false; window.Livewire.dispatch('counter-my-hours')">
                        <x-counter.icon name="list" class="h-5 w-5 shrink-0" />{{ __('Mis horas') }}
                    </button>
                    <button type="button" role="menuitem" data-counter-switch-operator class="{{ $menuItem }}" @click="open = false; window.Livewire.dispatch('counter-switch-operator')">
                        <x-counter.icon name="user" class="h-5 w-5 shrink-0" />{{ __('Cambiar de persona') }}
                    </button>
                    <span data-counter-chip-menu-end hidden></span>
                </div>
            </div>

            {{-- The one-tap route for someone working unclocked (not in *Modo formación*: a practice clock-in would be
                 discarded). Gone once they have clocked in; never a blocker (281). --}}
            @if ($clock['state'] === 'out' && ! $practising)
                <button type="button" data-counter-clock-in-quick @click="window.Livewire.dispatch('counter-clock-in')"
                        aria-label="{{ __('Fichar entrada (no has fichado)') }}"
                        class="order-last inline-flex min-h-11 items-center rounded-lg border border-warning/50 bg-warning/10 px-3 text-sm font-semibold text-warning transition hover:bg-warning/20 md:order-none">
                    {{ __('Fichar entrada') }}
                </button>
            @endif
        @endif

        {{-- LOCK — prompt 198: a first-class control, on every screen, one tap, no navigation and no confirm. `lockNow()`
             raises the overlay AND dispatches `counter-lock`, which signs the operator out server-side. --}}
        <button type="button" data-counter-lock @click="$store.counter.lockNow()"
                aria-label="{{ __('Bloquear pantalla') }}" title="{{ __('Bloquear pantalla') }}"
                class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg px-3 text-sm font-medium text-ink-muted transition hover:bg-brand-tint hover:text-brand dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5 shrink-0" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 0h10.5a2.25 2.25 0 0 1 2.25 2.25v6.75a2.25 2.25 0 0 1-2.25 2.25H6.75a2.25 2.25 0 0 1-2.25-2.25v-6.75a2.25 2.25 0 0 1 2.25-2.25Z"/>
            </svg>
        </button>

        {{-- PANIC (prompt 121) — discreet and fast: an icon at the end, one tap plus the confirm, gated on
             `lockdown.initiate`, absent from the DOM without it, never announced. --}}
        @if ($user?->can('lockdown.initiate'))
            <form method="POST" action="{{ route('counter.panic') }}"
                  @submit="! window.confirm(@js(__('¿Activar el bloqueo de seguridad? Cerrará el club entero.'))) && $event.preventDefault()">
                @csrf
                <button type="submit" data-counter-panic aria-label="{{ __('Bloqueo de seguridad') }}"
                        class="inline-flex h-11 w-11 items-center justify-center rounded-lg text-ink-muted/60 transition hover:bg-error/10 hover:text-error dark:text-slate-500 dark:hover:bg-error/10">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/>
                    </svg>
                </button>
            </form>
        @endif

        {{-- ⋯ MÁS — everything else, labelled: *Modo formación* (324), *Instalar como app* (290), *Este dispositivo* (289),
             *Administración* and *Salir* (the leave group, 206/239/246) — each with its existing gate and confirmation. --}}
        <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false" @click.outside="open = false">
            <button type="button" data-counter-more @click="open = ! open" aria-haspopup="true" :aria-expanded="open.toString()"
                    aria-label="{{ __('Más') }}" title="{{ __('Más') }}"
                    class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg px-3 text-ink-muted transition hover:bg-brand-tint hover:text-brand dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM12.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM18.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z"/></svg>
            </button>
            <div x-show="open" x-cloak data-counter-more-menu class="{{ $menuPanel }}" role="menu">
                {{-- Prompt 324 — practise on the real counter, nothing kept. Asks first (the chrome's sheet). --}}
                @if ($chipOperator !== null && ! $practising && (bool) \App\Support\Settings::get('counter_training_enabled', true, session('counter.location_id')))
                    <button type="button" role="menuitem" data-counter-training class="{{ $menuItem }}" @click="open = false; $dispatch('counter-sheet-open', { name: 'training' })">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5 shrink-0" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342"/></svg>
                        {{ __('Modo formación') }}
                    </button>
                @endif
                {{-- Prompt 290 — only when the browser has offered installation, never once installed. --}}
                <button type="button" role="menuitem" data-counter-install x-cloak class="{{ $menuItem }}"
                        x-data="{ can: !! window.cscInstallPrompt && ! window.matchMedia('(display-mode: standalone)').matches }"
                        x-show="can"
                        x-on:csc-installable.window="can = ! window.matchMedia('(display-mode: standalone)').matches"
                        x-on:csc-installed.window="can = false"
                        @click="window.cscInstallPrompt?.prompt(); window.cscInstallPrompt?.userChoice.finally(() => { window.cscInstallPrompt = null; can = false })">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5 shrink-0" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                    {{ __('Instalar como app') }}
                </button>
                {{-- Prompt 289 — register THIS tablet as a counter, or forget it. terminals.manage only; asks for the PIN again. --}}
                @if ($chipOperator?->can('terminals.manage'))
                    @php($thisTerminal = \App\Support\CounterTerminals::current())
                    <button type="button" role="menuitem" data-counter-terminal class="{{ $menuItem }}" @click="open = false; window.Livewire.dispatch('counter-terminal')"
                            title="{{ $thisTerminal ? __('Este dispositivo: :name · :sede', ['name' => $thisTerminal->name, 'sede' => $thisTerminal->location?->name]) : __('Registrar este dispositivo como mostrador') }}">
                        <x-counter.icon name="tablet" class="h-5 w-5 shrink-0" />
                        {{ __('Este dispositivo') }}
                    </button>
                @endif
                @if ($canPanel || $canManageDevice)
                    <div data-counter-leave-group class="mt-1 border-t border-line pt-1 dark:border-slate-800">
                        @if ($canPanel)
                            {{-- Leaves the counter: confirms unsaved work (`counter.dirty`), as before. --}}
                            <a href="{{ url('/') }}" role="menuitem" data-counter-admin-link wire:navigate.ignore class="{{ $menuItem }}"
                               @click.prevent="(! ($store.counter?.dirty) || window.confirm(@js($confirmLeave))) && window.location.assign('{{ url('/') }}')">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5 shrink-0" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 0 0 .75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 0 0-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0 1 12 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 0 1-.673-.38m0 0A2.18 2.18 0 0 1 3 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 0 1 3.413-.387m7.5 0V5.25A2.25 2.25 0 0 0 13.5 3h-3a2.25 2.25 0 0 0-2.25 2.25v.894m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                                {{ __('Administración') }}
                            </a>
                        @endif
                        {{-- THE DEVICE session — a responsable's act (239): staff.manage, and it asks first. --}}
                        @if ($canManageDevice)
                            <form method="POST" action="{{ route('filament.admin.auth.logout') }}"
                                  @submit="(! window.confirm(@js($confirmDeviceLogout))) && $event.preventDefault()">
                                @csrf
                                <button type="submit" role="menuitem" data-counter-logout title="{{ __('Cerrar sesión del dispositivo') }}" class="{{ $menuItem }}">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5 shrink-0" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75"/></svg>
                                    {{ __('Salir') }}
                                </button>
                            </form>
                        @endif
                    </div>
                @endif
                <span data-counter-more-menu-end hidden></span>
            </div>
        </div>
    </div>
</header>
