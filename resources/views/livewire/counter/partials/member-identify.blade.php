{{--
    Identifying a socio: the scan field, the optional camera, and the name / nº search.

    Extracted in prompt 175 because the member precondition is the one whose fix lives ON the blocked screen.
    The other three send the operator elsewhere (the topbar sede switcher, the Caja screen) so their blocking
    state can be a link; this one has to carry the control itself, or the blocker is a dead end.

    Deliberately WITHOUT card chrome — the caller wraps it. The blocking state is already the whole screen and
    does not want a card inside a card; the identified-member column does.
--}}
<form wire:submit="submitScan">
    <label for="scan" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Escanear tarjeta o buscar socio') }}</label>
    <input
        id="scan"
        type="text"
        wire:model="scan"
        autofocus
        autocomplete="off"
        spellcheck="false"
        placeholder="{{ __('Escanea la tarjeta, o escribe nombre / nº y pulsa Enter') }}"
        class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
    >
</form>

@if ($cameraScanEnabled)
    <x-counter.camera-scan />
@endif

<div class="mt-3">
    <label for="member-search" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('o busca por nombre / nº de socio') }}</label>
    <input
        id="member-search"
        type="text"
        wire:model.live.debounce.300ms="search"
        autocomplete="off"
        placeholder="{{ __('Ej. García o M-00042') }}"
        class="mt-2 h-11 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
    >

    @if ($searchResults !== null)
        <ul class="mt-2 divide-y divide-line overflow-hidden rounded-xl border border-line dark:divide-slate-800 dark:border-slate-800">
            @forelse ($searchResults as $result)
                <li>
                    <button type="button" wire:click="selectMember('{{ $result->id }}')" class="flex min-h-[2.75rem] w-full items-center justify-between gap-3 bg-surface px-4 py-3 text-left transition hover:bg-surface-alt dark:bg-slate-900 dark:hover:bg-slate-800">
                        <span class="font-medium">{{ $result->fullName() }}</span>
                        <span class="text-sm text-ink-muted dark:text-slate-400">{{ $result->member_no }}</span>
                    </button>
                </li>
            @empty
                <li class="bg-surface px-4 py-3 text-sm text-ink-muted dark:bg-slate-900 dark:text-slate-400">{{ __('Sin resultados.') }}</li>
            @endforelse
        </ul>
    @endif

    @if ($requireCheckedIn)
        <p class="mt-2 text-xs text-ink-muted dark:text-slate-500">{{ __('Esta sede solo permite dispensar a socios que han registrado su entrada.') }}</p>
    @endif
</div>
