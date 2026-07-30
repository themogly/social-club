@props(['title' => null])
@php
    // The SAME gate the sidebar uses (User::canAccessPanel): a fixed counter-only login
    // with no panel access sees no way into admin — that lockdown is intentional.
    $user = auth()->user();
    $canPanel = $user !== null && $user->canAccessPanel(\Filament\Facades\Filament::getPanel('admin'));
    $confirmLeave = __('Tienes trabajo sin guardar en el mostrador. ¿Seguro que quieres salir?');
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
            <p class="text-xs text-ink-muted dark:text-slate-400">{{ $title ?? __('Contador') }}</p>
        </div>
    </div>

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
