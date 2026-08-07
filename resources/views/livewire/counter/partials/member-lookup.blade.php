{{--
    THE member lookup (prompt 194) — one field, one label, on every counter screen that identifies a socio.

    It replaces seven inputs across five screens. Two of those screens stacked a scan box directly above a
    name box, each of which already did the other's job; the other three offered a name box with no scan
    affordance, so a card scanned there landed in a name search and found nothing. The operator learned a
    rule nobody designed: that scanning "works on Dispensario but not on Socios".

    The behaviour is in App\Livewire\Counter\Concerns\FindsMembers. This is only its surface.

    Deliberately WITHOUT card chrome — the caller wraps it. A blocking state is already the whole screen and
    does not want a card inside a card; a cart column does.

    $autofocus — the screen whose entire purpose is identifying somebody focuses this; a cart column does not
    steal focus from the basket.
--}}
<div>
    <form wire:submit="submitLookup">
        <label for="member-lookup" class="block text-sm font-medium text-ink-muted dark:text-slate-400">
            {{ $this->lookupLabel() }}
        </label>
        <input
            id="member-lookup"
            data-member-lookup
            type="text"
            wire:model="lookup"
            @if ($autofocus ?? false) autofocus @endif
            autocomplete="off"
            spellcheck="false"
            placeholder="{{ $this->lookupPlaceholder() }}"
            class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
        >
    </form>

    {{-- Camera scan is the one part that IS feature-detectable, and the component already does it: it hides
         itself where BarcodeDetector is missing, and needs a secure context. Per-sede, off by default. --}}
    @if ($cameraScanEnabled ?? false)
        <x-counter.camera-scan />
    @endif

    @php($results = $this->lookupResults())

    @if ($results !== null)
        <ul data-member-lookup-results class="mt-2 divide-y divide-line overflow-hidden rounded-xl border border-line dark:divide-slate-800 dark:border-slate-800">
            @forelse ($results as $result)
                <li>
                    {{-- The whole row is the target, comfortably over the counter's 44px floor. --}}
                    <button
                        type="button"
                        wire:click="selectMember('{{ $result->id }}')"
                        data-member-lookup-result
                        class="flex min-h-[2.75rem] w-full items-center justify-between gap-3 bg-surface px-4 py-3 text-left transition hover:bg-surface-alt dark:bg-slate-900 dark:hover:bg-slate-800"
                    >
                        <span class="font-medium">{{ $result->fullName() }}</span>
                        <span class="text-sm text-ink-muted dark:text-slate-400">{{ $result->member_no }}</span>
                    </button>
                </li>
            @empty
                {{-- An empty result is a search MISS, not a scan failure — nothing here has touched the
                     failed-scan throttle (prompt 58). Thirty of these in a shift lock nothing. --}}
                <li data-member-lookup-empty class="bg-surface px-4 py-3 text-sm text-ink-muted dark:bg-slate-900 dark:text-slate-400">{{ __('Sin resultados.') }}</li>
            @endforelse
        </ul>
    @endif
</div>
