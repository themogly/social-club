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

    // The screen switcher. Each entry is shown ONLY if the current user passes that screen's
    // real per-screen gate (mirrored from each Livewire component's mount()), so a link to a
    // 403 is never rendered. `granted` is resolved here; the markup below is presentation only.
    $screens = array_values(array_filter([
        ['route' => 'counter.checkin', 'label' => __('Recepción'), 'granted' => (bool) $user?->can('checkin.manage'),
            'icon' => 'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z'],
        ['route' => 'counter.members', 'label' => __('Socios'), 'granted' => (bool) $user?->can('membership.fee.collect'),
            'icon' => 'M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z'],
        ['route' => 'counter.pos', 'label' => __('Dispensario'), 'granted' => (bool) $user?->can('pos.use'),
            'icon' => 'M12 3v17.25m0 0c-1.472 0-2.882.265-4.185.75M12 20.25c1.472 0 2.882.265 4.185.75M18.75 4.97A48.416 48.416 0 0 0 12 4.5c-2.291 0-4.545.16-6.75.47m13.5 0c1.01.143 2.01.317 3 .52m-3-.52 2.62 10.726c.122.499-.106 1.028-.589 1.202a5.988 5.988 0 0 1-2.031.352 5.988 5.988 0 0 1-2.031-.352c-.483-.174-.711-.703-.59-1.202L18.75 4.971Zm-16.5.52c.99-.203 1.99-.377 3-.52m0 0 2.62 10.726c.122.499-.106 1.028-.589 1.202a5.989 5.989 0 0 1-2.031.352 5.989 5.989 0 0 1-2.031-.352c-.483-.174-.711-.703-.59-1.202L5.25 4.971Z'],
        ['route' => 'counter.bar', 'label' => __('Barra'), 'granted' => (bool) $user?->can('pos.bar') && (bool) \App\Support\Settings::get('bar_enabled', true),
            'icon' => 'M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007Z'],
        ['route' => 'counter.till', 'label' => __('Caja'), 'granted' => (bool) ($user?->can('till.open') || $user?->can('till.close')),
            'icon' => 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z'],
    ], fn (array $s): bool => $s['granted']));
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
        <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand text-base font-bold text-white">
            {{ mb_substr(config('app.name', 'C'), 0, 1) }}
        </span>
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

    {{-- Secondary actions, collapsed behind ONE 44px overflow control (prompt 132). Help, Panel and Log out are
         the three items in the bar that are NOT a counter destination and the least-used; folding them into a
         single dropdown is what lets the whole bar be one non-overlapping flow at every width — the widened
         five-destination primary group (130 + 127) no longer runs into a wide fixed secondary group, because
         there isn't one any more. The trigger and every menu item are 44px. `data-counter-help` /
         `data-counter-dashboard` / `data-counter-logout` are preserved for the existing tests. --}}
    <div
        x-data="{ open: false }"
        class="relative shrink-0"
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
            {{-- Panel + Log out (44px rows). --}}
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
</header>
