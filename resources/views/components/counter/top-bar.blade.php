@props(['title' => null])
@php
    // The SAME gate the sidebar uses (User::canAccessPanel): a fixed counter-only login
    // with no panel access sees no way into admin — that lockdown is intentional.
    $user = auth()->user();
    $canPanel = $user !== null && $user->canAccessPanel(\Filament\Facades\Filament::getPanel('admin'));

    // TWO confirms, because there are two different losses (prompt 206).
    //   · leaving the counter  — the basket goes with the session; `counter.dirty` is the right question.
    //   · going home           — the basket SURVIVES (App\Support\CounterBasket), so the only thing a
    //                            navigation inside the counter can lose is typed-but-unsubmitted input,
    //                            which is `counter.volatile` and today means the till's count/movement
    //                            fields only.
    $confirmLeave = __('Tienes trabajo sin guardar en el mostrador. ¿Seguro que quieres salir?');
    $confirmDiscard = __('Hay datos sin guardar en esta pantalla. Se perderán. ¿Continuar?');

    // Whose terminal this is (prompt 206). 205 left only the PRODUCT name's first letter in an aria-hidden
    // tile, so nothing on any counter screen said which club the staff were working at — and the product
    // name is the wrong name anyway (prompt 150 records the same mistake on club email). One indexed lookup.
    $clubName = \App\Support\OrganisationIdentity::tradingName();

    // Which sede this terminal is working at (prompt 89). Resolved HERE, in the one shared header, so all
    // four counter screens show it identically. Read from the counter's OWN state (session
    // `counter.location_id`) — never the admin panel scope, and switching goes through the validated
    // POST /counter/location route. A single-sede operator shows their only sede even before the component
    // has persisted the adoption; several sedes with none chosen ⇒ the operator must pick (never a guess).
    $availableSedes = $user !== null ? app(\App\Support\LocationSwitcher::class)->available($user) : collect();
    $currentSedeId = session('counter.location_id');
    $currentSede = is_string($currentSedeId) ? $availableSedes->firstWhere('id', $currentSedeId) : null;
    if ($currentSede === null && $availableSedes->count() === 1) {
        $currentSede = $availableSedes->first();
    }
    $mustChooseSede = $currentSede === null && $availableSedes->count() > 1;
    $noSede = $availableSedes->isEmpty();
    $sedeSwitchError = session('counterLocationError');

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
    class="flex items-center justify-between border-b border-line px-4 py-3 dark:border-slate-800 sm:px-6"
>
    <div class="flex min-w-0 items-center gap-3">
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
               'text-ink hover:bg-brand-tint hover:text-brand dark:text-slate-100 dark:hover:bg-slate-800' => ! request()->routeIs('counter.home'),
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
                <span class="block truncate text-sm font-semibold">{{ $clubName }}</span>
                {{-- The counter screen's one <h1> (a11y): the shared header renders it for every terminal,
                     so headings below can start at h2 without skipping a level. --}}
                <h1 class="truncate text-xs font-normal text-ink-muted dark:text-slate-400">{{ $title ?? __('Mostrador') }}</h1>
            </span>
            {{-- …and where the link GOES, which the club's name alone does not say. Same idiom as the
                 operator chip's "· Cambiar" below. --}}
            <span class="sr-only">· {{ __('Inicio del mostrador') }}</span>
        </a>

        {{-- Which sede this terminal is working at (prompt 89) — shown on EVERY counter screen, from the
             one shared header. Zero sedes: a warning. One sede: a static badge (nothing to switch to).
             Several: a switcher (each a validated POST to /counter/location, confirming unsaved work);
             several with none chosen yet ⇒ a highlighted "choose your sede" prompt, never a silent guess. --}}
        <div class="relative shrink-0" data-counter-sede-region>
            @if ($noSede)
                <span data-counter-sede-state="none"
                      class="inline-flex items-center gap-1.5 rounded-lg bg-warning/10 min-h-11 px-3 text-sm font-medium text-warning">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                    {{ __('Sin sede') }}
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
                                'bg-surface-alt text-ink hover:bg-brand-tint hover:text-brand dark:bg-slate-800 dark:text-slate-100' => ! $mustChooseSede,
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
                                            'font-medium text-ink hover:bg-brand-tint hover:text-brand dark:text-slate-200 dark:hover:bg-slate-800' => ! $isCurrent,
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

            @if ($sedeSwitchError)
                <p role="alert" data-counter-sede-error
                   class="absolute left-0 top-full z-30 mt-1 w-max max-w-xs rounded-lg bg-error px-2.5 py-1.5 text-xs font-medium text-white shadow">
                    {{ $sedeSwitchError }}
                </p>
            @endif
        </div>
    </div>

    {{-- The five-destination tab strip stood here and is GONE (prompt 205). The hub is the menu now: the
         destinations were in this bar AND on the hub after 189, which is the "duplicate data" the owner
         reported, and a strip that took prompts 116, 130 and 132 to fit on a portrait tablet was the more
         expensive of the two copies to keep. `CounterScreens` is unchanged and is read by the tiles. --}}
    <div class="min-w-0 flex-1"></div>

    {{-- ============ THE TERMINAL CONTROLS ============
         Every one of these was in TWO places after 189 — here and on the hub's "Terminal" panel. They live
         here now and only here (prompt 205), because they are facts about this terminal rather than about
         whichever screen happens to be open.

         **Labels collapse below `xl`, raised from `lg` by prompt 206**, and 130's rule that labelling is
         all-or-nothing is why it had to move rather than half-collapse: this row grew — the club's name went
         back into the home link and *Panel* became the longer, correct *Administración* — and a labelled row
         no longer fits the 1024px landscape tablet. Measured, not guessed: at 1024 the labelled row overlapped
         the sede badge by 68px. Every target stays at the 44px floor either way, and every control carries an
         `aria-label`, because `hidden` takes a label out of the accessibility tree as well as off the screen —
         the Lock button was relying on a span that vanished from both below `lg`. --}}
    <div class="flex shrink-0 items-center gap-1">
        {{-- WHO IS WORKING, and Switch — one control (prompt 173's rule: exactly ONE route to the pad, which
             is 173's own full-screen surface; this dispatches to it and does not draw a second one). --}}
        @if ($user !== null && \App\Support\CounterOperator::current() !== null)
            <button
                type="button"
                data-operator-name-chip
                data-counter-switch-operator
                @click="window.Livewire.dispatch('counter-switch-operator')"
                class="inline-flex min-h-11 items-center gap-2 rounded-lg bg-surface-alt px-3 text-sm transition hover:bg-brand-tint hover:text-brand dark:bg-slate-800 dark:hover:bg-slate-700"
            >
                <span class="inline-block h-2.5 w-2.5 shrink-0 rounded-full bg-success" aria-hidden="true"></span>
                <span class="hidden text-ink-muted xl:inline dark:text-slate-400">{{ __('Trabajando') }}:</span>
                <span data-operator-name class="max-w-[9rem] truncate font-semibold">{{ \App\Support\CounterOperator::current()?->name }}</span>
                <span class="sr-only">· {{ __('Cambiar') }}</span>
            </button>
        @endif

        {{-- LOCK — prompt 198's requirement, settled: a first-class control in the bar, on every screen,
             one tap, no navigation and no confirm. 198 existed because the only lock was on the hub, so
             locking mid-sale meant leaving the screen; with the lock here that trip is gone entirely.
             `lockNow()` is the store's own method: it raises the overlay AND dispatches `counter-lock`,
             which signs the operator out server-side so writes are refused, not merely hidden. --}}
        <button
            type="button"
            data-counter-lock
            @click="$store.counter.lockNow()"
            aria-label="{{ __('Bloquear pantalla') }}"
            class="inline-flex min-h-11 items-center gap-2 rounded-lg px-3 text-sm font-medium text-ink-muted transition hover:bg-brand-tint hover:text-brand dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"
        >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5 shrink-0" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 0h10.5a2.25 2.25 0 0 1 2.25 2.25v6.75a2.25 2.25 0 0 1-2.25 2.25H6.75a2.25 2.25 0 0 1-2.25-2.25v-6.75a2.25 2.25 0 0 1 2.25-2.25Z"/>
            </svg>
            <span class="hidden xl:inline" aria-hidden="true">{{ __('Bloquear pantalla') }}</span>
        </button>

        {{-- ======== THE CONTROLS THAT LEAVE THE COUNTER (prompt 206) ========
             Everything above stays INSIDE the counter — Home and Lock change nothing about the session.
             These two end it: Administración opens a different application, Log out takes the session with
             it. Both already confirmed unsaved work, and that shared behaviour is exactly the tell that they
             are one group — so they are one group, behind a divider. Nothing moved and nothing was renamed to
             achieve it; grouping by SCOPE is what makes the row legible.

             The divider is decorative and the group is not a landmark: the separation is visual, and the
             accessible names below carry the meaning on their own. --}}
        <div data-counter-leave-group class="ml-1 flex items-center gap-1 border-l border-line pl-2 dark:border-slate-800">
            @if ($canPanel)
                {{-- ADMINISTRACIÓN — was "Panel", which `lang/en.json` rendered as **Dashboard**, making it a
                     synonym of the Home link a few pixels away (and the hub is a dashboard too, so the word
                     could not stay). It is named for its destination now: this is the way OUT of the counter
                     and into the back office, and the briefcase says office where the house says home. --}}
                <a
                    href="{{ url('/') }}"
                    data-counter-admin-link
                    wire:navigate.ignore
                    @click.prevent="(! ($store.counter?.dirty) || window.confirm(@js($confirmLeave))) && window.location.assign('{{ url('/') }}')"
                    aria-label="{{ __('Administración') }}"
                    class="inline-flex min-h-11 items-center gap-2 rounded-lg px-3 text-sm font-medium text-ink-muted transition hover:bg-brand-tint hover:text-brand dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5 shrink-0" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 0 0 .75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 0 0-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0 1 12 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 0 1-.673-.38m0 0A2.18 2.18 0 0 1 3 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 0 1 3.413-.387m7.5 0V5.25A2.25 2.25 0 0 0 13.5 3h-3a2.25 2.25 0 0 0-2.25 2.25v.894m7.5 0a48.667 48.667 0 0 0-7.5 0"/>
                    </svg>
                    <span class="hidden xl:inline" aria-hidden="true">{{ __('Administración') }}</span>
                </a>
            @endif

            <form
                method="POST"
                action="{{ route('filament.admin.auth.logout') }}"
                @submit="($store.counter?.dirty && ! window.confirm(@js($confirmLeave))) && $event.preventDefault()"
            >
                @csrf
                <button
                    type="submit"
                    data-counter-logout
                    aria-label="{{ __('Cerrar sesión') }}"
                    class="inline-flex min-h-11 items-center gap-2 rounded-lg px-3 text-sm font-medium text-ink-muted transition hover:bg-black/5 dark:text-slate-400 dark:hover:bg-white/5"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5 shrink-0" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75"/>
                    </svg>
                    <span class="hidden xl:inline" aria-hidden="true">{{ __('Cerrar sesión') }}</span>
                </button>
            </form>
        </div>

        {{-- PANIC (prompt 121). The hardest thing 205 had to rehome: the overflow it lived in is gone, and
             121 requires it to stay DISCREET and FAST — a labelled button on a hub is neither.

             Resolved as an icon-only 44×44 control at the end of this row: **one tap plus the confirm**,
             which is one tap FEWER than the overflow it replaces, and it carries no wording that announces
             itself to a room — the accessible name is there for a screen reader, and the shield is not a
             word anybody reads across a counter. Everything 121 guarantees is untouched: gated on
             `lockdown.initiate`, absent from the DOM without it, confirms before firing, and never
             announced. --}}
        @if ($user?->can('lockdown.initiate'))
            <form
                method="POST"
                action="{{ route('counter.panic') }}"
                @submit="! window.confirm(@js(__('¿Activar el bloqueo de seguridad? Cerrará el club entero.'))) && $event.preventDefault()"
            >
                @csrf
                <button
                    type="submit"
                    data-counter-panic
                    aria-label="{{ __('Bloqueo de seguridad') }}"
                    class="inline-flex h-11 w-11 items-center justify-center rounded-lg text-ink-muted/60 transition hover:bg-error/10 hover:text-error dark:text-slate-500 dark:hover:bg-error/10"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/>
                    </svg>
                </button>
            </form>
        @endif
    </div>
</header>
