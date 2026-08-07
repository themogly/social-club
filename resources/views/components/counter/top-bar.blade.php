@props(['title' => null])
@php
    // The SAME gate the sidebar uses (User::canAccessPanel): a fixed counter-only login
    // with no panel access sees no way into admin — that lockdown is intentional.
    $user = auth()->user();
    $canPanel = $user !== null && $user->canAccessPanel(\Filament\Facades\Filament::getPanel('admin'));
    $confirmLeave = __('Tienes trabajo sin guardar en el mostrador. ¿Seguro que quieres salir?');

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

    // The screen switcher. Each entry is shown ONLY if the current user passes that screen's real
    // per-screen gate (mirrored from each Livewire component's mount()), so a link to a 403 is never
    // rendered. The list moved to App\Support\CounterScreens (prompt 172) so the panel's single counter
    // link can ask the same question — "can this user reach the counter, and where should one link land
    // them?" — without a second copy of the rule. Destinations, gates and layout are unchanged.
    $screens = \App\Support\CounterScreens::reachableFor($user);
@endphp

{{--
    The one shared counter header. Every counter screen renders THIS component (via the
    counter layout), so a future fifth screen gets the back-to-dashboard affordance for
    free. Minimal-chrome, kiosk-feel: brand + screen title on the left; a permission-
    filtered "Panel" link and "Log out" on the right. Leaving with unsaved work
    (Alpine store `counter.dirty`, set by the POS/till screens) confirms first.
--}}
<header
    data-counter-topbar
    class="flex items-center justify-between border-b border-line px-4 py-3 dark:border-slate-800 sm:px-6"
>
    <div class="flex min-w-0 items-center gap-3">
        {{-- Prompt 189: the brand block is now the way HOME. The hub carries the terminal operations that used
             to crowd this row, so it has to be one tap from every screen. --}}
        <a href="{{ route('counter.home') }}"
           data-counter-home-link
           wire:navigate.ignore
           @click.prevent="(! ($store.counter?.dirty) || window.confirm(@js($confirmLeave))) && window.location.assign('{{ route('counter.home') }}')"
           aria-label="{{ __('Inicio del mostrador') }}"
           class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand text-base font-bold text-white transition hover:bg-brand-dark">
            {{ mb_substr(config('app.name', 'C'), 0, 1) }}
        </a>
        {{-- Prompt 130: the brand/title block can shrink and TRUNCATE (min-w-0 + truncate) rather than shoving
             the nav into its neighbours on a narrow (portrait-tablet) header. It stays rendered at every width so
             the counter screen's single <h1> is never dropped from the a11y tree. --}}
        <div class="min-w-0 leading-tight">
            <p class="truncate text-sm font-semibold">{{ config('app.name') }}</p>
            {{-- The counter screen's one <h1> (a11y): the shared header renders it for every
                 terminal, so headings below can start at h2 without skipping a level. --}}
            <h1 class="truncate text-xs text-ink-muted dark:text-slate-400">{{ $title ?? __('Mostrador') }}</h1>
        </div>

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

    @if (count($screens) > 0)
        {{-- Screen switcher (prompt 42/130): jump straight between the counter screens the operator is allowed
             to use — the current one marked active, not a link. Same unsaved-work confirm as the Panel link.
             Prompt 130: it is a flex-1, min-w-0, horizontally-SCROLLABLE strip, so it takes only the middle
             space between the brand/sede block and the right-hand actions and can NEVER overlap them (116's
             `md:inline` labelled all five destinations at 768px, which on a portrait tablet overflowed into the
             neighbours). Labels appear UNIFORMLY only from `lg` up, where a labelled strip actually fits; below
             that every item is an equal 44px icon-only target and the strip scrolls if it still runs out. --}}
        <nav aria-label="{{ __('Cambiar de pantalla') }}" class="flex min-w-0 flex-1 items-center gap-1 overflow-x-auto">
            @foreach ($screens as $screen)
                @php $active = request()->routeIs($screen['route']); @endphp
                @if ($active)
                    <span
                        aria-current="page"
                        data-counter-screen="{{ $screen['route'] }}"
                        class="inline-flex h-11 shrink-0 items-center gap-1.5 rounded-lg bg-brand-tint px-3 text-sm font-semibold text-brand dark:bg-slate-800 dark:text-white"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $screen['icon'] }}"/></svg>
                        <span class="hidden lg:inline">{{ $screen['label'] }}</span>
                    </span>
                @else
                    <a
                        href="{{ route($screen['route']) }}"
                        data-counter-screen="{{ $screen['route'] }}"
                        data-counter-switch="{{ $screen['route'] }}"
                        wire:navigate.ignore
                        @click.prevent="(! ($store.counter?.dirty) || window.confirm(@js($confirmLeave))) && window.location.assign('{{ route($screen['route']) }}')"
                        class="inline-flex h-11 shrink-0 items-center gap-1.5 rounded-lg px-3 text-sm font-medium text-ink-muted transition hover:bg-brand-tint hover:text-brand dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $screen['icon'] }}"/></svg>
                        <span class="hidden lg:inline">{{ $screen['label'] }}</span>
                    </a>
                @endif
            @endforeach
        </nav>
    @endif

    {{-- WHO IS WORKING (prompt 173). The inline operator strip is retired — it was a normal-flow block that
         pushed the page down when its PIN pad opened — so the operator's name lives here instead, in the
         chrome that is already on every screen and already at the 44px floor. Reading only: identifying and
         switching both happen on the full-screen surface, so there is exactly ONE route to the pad. --}}
    @if ($user !== null && \App\Support\CounterOperator::current() !== null)
        <p data-operator-name-chip class="hidden items-center gap-2 rounded-lg bg-surface-alt px-3 py-2 text-sm xl:flex dark:bg-slate-800">
            <span class="inline-block h-2.5 w-2.5 rounded-full bg-success" aria-hidden="true"></span>
            <span class="text-ink-muted dark:text-slate-400">{{ __('Trabajando') }}:</span>
            <span data-operator-name class="font-semibold">{{ \App\Support\CounterOperator::current()?->name }}</span>
        </p>
    @endif

    {{-- Trailing controls (prompts 120 + 132): the "lock now" button plus ONE overflow control that collapses
         the non-destination actions (Help, Panel, Log out). Both are 44px; the overflow is what lets the widened
         five-destination nav be one flow that cannot overlap a wide fixed group — there isn't one any more. --}}
    <div class="flex shrink-0 items-center gap-1">
        {{-- Prompt 189 moved the dedicated "lock now" BUTTON off this row to the counter home, with the other
             operations that are not specific to the current transaction. That was right for the row — it was a
             44px control the owner had called cramped — but the premise that came with it, "locking is not
             something you do mid-basket", was wrong, and prompt 198 measured the cost.

             Locking is precisely what you do mid-basket: it is what happens when you step away from a counter
             with a member in front of you. With the only control on `/counter`, reaching it crossed prompt
             196's unsaved-work confirm — correct, newly working, and exactly what made it expensive — so the
             operator's real choice mid-order was to leave the terminal unlocked or abandon the sale. The idle
             timer, firing in place, always kept the basket; the DELIBERATE control was the one that lost it.

             So the lock comes back to the bar as a MENU ITEM rather than a button: one tap more than before,
             no new furniture in the row, no navigation, and the overflow is already an x-data island so the
             handler binds (prompt 196). The home tile stays as the discoverable route. --}}

        {{-- Secondary actions, collapsed behind ONE 44px overflow control (prompt 132). Help, Panel and Log out
             — the three items that are NOT a counter destination — folded into a single dropdown so the widened
             five-destination row runs into no wide fixed secondary group. data-counter-help /
             data-counter-dashboard / data-counter-logout preserved for the tests. --}}
        <div
            x-data="{ open: false }"
            class="relative"
            data-counter-overflow
            data-counter-help
        >
        <button
            type="button"
            @click="open = ! open"
            aria-haspopup="true"
            :aria-expanded="open.toString()"
            aria-label="{{ __('Más opciones') }}"
            data-counter-overflow-trigger
            class="inline-flex h-11 w-11 items-center justify-center rounded-lg text-ink-muted transition hover:bg-brand-tint hover:text-brand dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"
        >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM12.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM18.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z"/>
            </svg>
        </button>

        <div
            x-show="open"
            x-cloak
            @click.outside="open = false"
            @keydown.escape.window="open = false"
            class="absolute right-0 z-40 mt-1 w-80 max-w-[90vw] rounded-xl border border-line bg-surface p-2 text-left shadow-lg dark:border-slate-700 dark:bg-slate-900"
        >
            {{-- Lock, first (prompt 198): the only item here that does NOT leave the counter, and the one
                 with a person waiting. Deliberately NOT behind the unsaved-work confirm the two below carry
                 — locking preserves the basket by design, so asking whether work would be lost is both
                 wrong and the whole bug. `lockNow()` is the store's own method, unchanged: it flips the
                 overlay AND dispatches `counter-lock`, which signs the operator out server-side. --}}
            <button
                type="button"
                data-counter-overflow-lock
                @click="open = false; $store.counter.lockNow()"
                class="flex min-h-11 w-full items-center gap-2 rounded-lg px-3 text-sm font-medium text-ink-muted transition hover:bg-brand-tint hover:text-brand dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5 shrink-0" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 0h10.5a2.25 2.25 0 0 1 2.25 2.25v6.75a2.25 2.25 0 0 1-2.25 2.25H6.75a2.25 2.25 0 0 1-2.25-2.25v-6.75a2.25 2.25 0 0 1 2.25-2.25Z"/>
                </svg>
                {{ __('Bloquear pantalla') }}
            </button>

            {{-- Panel + Log out (44px rows) — both LEAVE the counter, so both keep 196's confirm. --}}
            @if ($canPanel)
                <a
                    href="{{ url('/') }}"
                    data-counter-dashboard
                    wire:navigate.ignore
                    @click.prevent="(! ($store.counter?.dirty) || window.confirm(@js($confirmLeave))) && window.location.assign('{{ url('/') }}')"
                    class="flex min-h-11 items-center gap-2 rounded-lg px-3 text-sm font-medium text-ink-muted transition hover:bg-brand-tint hover:text-brand dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5 shrink-0" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12 12 2.25 21.75 12M4.5 9.75v9.75a.75.75 0 0 0 .75.75H9.75V15.75a1.5 1.5 0 0 1 1.5-1.5h1.5a1.5 1.5 0 0 1 1.5 1.5v4.5h4.5a.75.75 0 0 0 .75-.75V9.75"/>
                    </svg>
                    {{ __('Panel') }}
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
                    class="flex min-h-11 w-full items-center gap-2 rounded-lg px-3 text-sm font-medium text-ink-muted transition hover:bg-black/5 dark:text-slate-400 dark:hover:bg-white/5"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5 shrink-0" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75"/>
                    </svg>
                    {{ __('Cerrar sesión') }}
                </button>
            </form>

            {{-- Panic lockdown (prompt 121): a discreet, staff-reachable trigger — they are the ones in the room.
                 Confirms first (an accidental trip locks the whole club), then trips the org-wide lockdown; the
                 next request shows the ordinary "unavailable" screen. Gated on lockdown.initiate. --}}
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
                        class="mt-1 flex min-h-11 w-full items-center gap-2 rounded-lg border-t border-line px-3 pt-2 text-sm font-medium text-error transition hover:bg-error/5 dark:border-slate-800"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5 shrink-0" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 0h10.5a2.25 2.25 0 0 1 2.25 2.25v6.75a2.25 2.25 0 0 1-2.25 2.25H6.75a2.25 2.25 0 0 1-2.25-2.25v-6.75a2.25 2.25 0 0 1 2.25-2.25Z"/>
                        </svg>
                        {{ __('Bloqueo de seguridad') }}
                    </button>
                </form>
            @endif

            {{-- Ayuda (prompt 92): the same answers to the blocked states staff hit, folded into the one overflow
                 menu (prompt 132). Static — nothing loads. Rules are NAMED, never a hard-coded value. --}}
            <div class="mt-1 border-t border-line px-1 pt-2 dark:border-slate-800" data-counter-help-content>
                <h2 class="px-2 text-sm font-semibold">{{ __('¿Por qué no puedo dispensar a un socio?') }}</h2>
                <ul class="mt-1 space-y-1 px-2 text-sm text-ink-muted dark:text-slate-400">
                    <li>· {{ __('No tiene una membresía activa, o no está al corriente de la cuota.') }}</li>
                    <li>· {{ __('Está en carencia: el período de espera desde el alta aún no ha terminado.') }}</li>
                    <li>· {{ __('Alcanzaría el límite diario o mensual de gramos configurado.') }}</li>
                    <li>· {{ __('No cumple la edad mínima, o su documento ha caducado.') }}</li>
                    <li>· {{ __('Debe dinero por encima del umbral permitido en el monedero.') }}</li>
                </ul>
                <p class="mt-2 px-2 text-xs text-ink-muted dark:text-slate-400">{{ __('El mostrador te dice el motivo exacto en cada caso. Un responsable puede autorizar algunas excepciones.') }}</p>

                <h3 class="mt-3 px-2 text-sm font-semibold">{{ __('Términos') }}</h3>
                <dl class="mt-1 space-y-1 px-2 pb-1 text-xs">
                    @foreach (['Aportación', 'Dispensación', 'Carencia', 'Arqueo'] as $term)
                        <div>
                            <dt class="inline font-semibold text-ink dark:text-slate-200">{{ $term }}:</dt>
                            <dd class="inline text-ink-muted dark:text-slate-400">{{ __(\App\Support\Help::GLOSSARY[$term]) }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>
        </div>
    </div>
</header>
