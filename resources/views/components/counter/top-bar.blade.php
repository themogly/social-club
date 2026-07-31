@props(['title' => null])
@php
    // The SAME gate the sidebar uses (User::canAccessPanel): a fixed counter-only login
    // with no panel access sees no way into admin — that lockdown is intentional.
    $user = auth()->user();
    $canPanel = $user !== null && $user->canAccessPanel(\Filament\Facades\Filament::getPanel('admin'));
    $confirmLeave = __('Tienes trabajo sin guardar en el mostrador. ¿Seguro que quieres salir?');

    // The screen switcher. Each entry is shown ONLY if the current user passes that screen's
    // real per-screen gate (mirrored from each Livewire component's mount()), so a link to a
    // 403 is never rendered. `granted` is resolved here; the markup below is presentation only.
    $screens = array_values(array_filter([
        ['route' => 'counter.checkin', 'label' => __('Recepción'), 'granted' => (bool) $user?->can('checkin.manage'),
            'icon' => 'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z'],
        ['route' => 'counter.pos', 'label' => __('Dispensario'), 'granted' => (bool) $user?->can('pos.use'),
            'icon' => 'M12 3v17.25m0 0c-1.472 0-2.882.265-4.185.75M12 20.25c1.472 0 2.882.265 4.185.75M18.75 4.97A48.416 48.416 0 0 0 12 4.5c-2.291 0-4.545.16-6.75.47m13.5 0c1.01.143 2.01.317 3 .52m-3-.52 2.62 10.726c.122.499-.106 1.028-.589 1.202a5.988 5.988 0 0 1-2.031.352 5.988 5.988 0 0 1-2.031-.352c-.483-.174-.711-.703-.59-1.202L18.75 4.971Zm-16.5.52c.99-.203 1.99-.377 3-.52m0 0 2.62 10.726c.122.499-.106 1.028-.589 1.202a5.989 5.989 0 0 1-2.031.352 5.989 5.989 0 0 1-2.031-.352c-.483-.174-.711-.703-.59-1.202L5.25 4.971Z'],
        ['route' => 'counter.bar', 'label' => __('Barra'), 'granted' => (bool) $user?->can('pos.bar'),
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
    <div class="flex items-center gap-3">
        <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-brand text-base font-bold text-white">
            {{ mb_substr(config('app.name', 'C'), 0, 1) }}
        </span>
        <div class="leading-tight">
            <p class="text-sm font-semibold">{{ config('app.name') }}</p>
            {{-- The counter screen's one <h1> (a11y): the shared header renders it for every
                 terminal, so headings below can start at h2 without skipping a level. --}}
            <h1 class="text-xs text-ink-muted dark:text-slate-400">{{ $title ?? __('Contador') }}</h1>
        </div>
    </div>

    @if (count($screens) > 0)
        {{-- Screen switcher (prompt 42): jump straight between the counter screens the operator is
             allowed to use — the current one marked active, not a link. Same unsaved-work confirm as
             the Panel link. Icon-only below lg, labels from lg up; scrolls if a narrow header runs out. --}}
        <nav aria-label="{{ __('Cambiar de pantalla') }}" class="flex min-w-0 items-center gap-1 overflow-x-auto">
            @foreach ($screens as $screen)
                @php $active = request()->routeIs($screen['route']); @endphp
                @if ($active)
                    <span
                        aria-current="page"
                        data-counter-screen="{{ $screen['route'] }}"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-brand-tint px-3 py-2 text-sm font-semibold text-brand dark:bg-slate-800 dark:text-white"
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
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-ink-muted transition hover:bg-brand-tint hover:text-brand dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $screen['icon'] }}"/></svg>
                        <span class="hidden lg:inline">{{ $screen['label'] }}</span>
                    </a>
                @endif
            @endforeach
        </nav>
    @endif

    <div class="flex items-center gap-1">
        @if ($canPanel)
            <a
                href="{{ url('/') }}"
                data-counter-dashboard
                wire:navigate.ignore
                @click.prevent="(! ($store.counter?.dirty) || window.confirm(@js($confirmLeave))) && window.location.assign('{{ url('/') }}')"
                class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-ink-muted transition hover:bg-brand-tint hover:text-brand dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12 12 2.25 21.75 12M4.5 9.75v9.75a.75.75 0 0 0 .75.75H9.75V15.75a1.5 1.5 0 0 1 1.5-1.5h1.5a1.5 1.5 0 0 1 1.5 1.5v4.5h4.5a.75.75 0 0 0 .75-.75V9.75"/>
                </svg>
                <span class="hidden sm:inline">{{ __('Panel') }}</span>
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
                class="rounded-lg px-3 py-2 text-sm font-medium text-ink-muted transition hover:bg-black/5 dark:text-slate-400 dark:hover:bg-white/5"
            >
                {{ __('Cerrar sesión') }}
            </button>
        </form>
    </div>
</header>
