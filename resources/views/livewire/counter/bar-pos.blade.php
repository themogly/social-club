{{-- Bar / merch POS — tablet-first, dark-mode first-class. Three panels: the socio
     (OPTIONAL, left), the article grid (centre) and the basket + payment (right). Every
     figure is live (prices, stock, balances). A THIN shell: CommitOrder is the domain
     boundary — this screen only assembles the call. Vocabulary is SALE (venta / ticket /
     importe), deliberately distinct from the cannabis contribution wording. Fail-closed
     offline: the commit is disabled and the basket preserved until reconnection. --}}
<div
    x-data="{
        online: true,
        init() {
            this.online = navigator.onLine;
            window.addEventListener('online', () => { this.online = true; $wire.set('offline', false); });
            window.addEventListener('offline', () => { this.online = false; $wire.set('offline', true); });
            if (! this.online) { $wire.set('offline', true); }
        },
    }"
    {{-- Prompt 176: the component root carries the height so the two-pane child can resolve `h-full`
         against a DEFINITE height and the cart column can be constrained instead of overflowing.
         `md:` only — below that the layout is a normal stacking, scrolling page. --}}
    class="md:h-full"
>
    @include('livewire.counter.partials.counter-surface')

    @if (! $this->handoverActive())

    {{-- Prompt 175 — one blocking state at a time, in dependency order. The bar has no member step (it can
         serve for cash), so MEMBER is absent from the chain rather than false. The operator step is reported
         so the till cannot jump it, and rendered by 173's surface. --}}
    @php
        $blocker = \App\Support\CounterBlocker::first([
            \App\Support\CounterBlocker::SEDE => ! $noLocation,
            \App\Support\CounterBlocker::OPERATOR => $this->hasOperator(),
            \App\Support\CounterBlocker::TILL => $openTill !== null,
        ]);
    @endphp

    {{-- Above the branch on purpose: losing the connection, or the reason a charge was refused, must reach the
         operator whichever state the screen is in. A blocking state replaces the work, not the warnings. --}}
    @if (! $noLocation)
        {{-- Offline banner — unmistakable, fail closed. --}}
        <div x-show="! online" x-cloak role="alert" aria-live="assertive" class="mb-4 flex items-center gap-3 rounded-xl border border-error/40 bg-error/10 px-4 py-3 text-sm font-semibold text-error">
            <span class="text-lg">⚠️</span>
            <span>{{ __('Sin conexión. No se puede registrar ninguna venta; la cesta se conserva y se reactivará al reconectar.') }}</span>
        </div>

        {{-- Prompts 192/193 — the flash used to live here unconditionally, ~650px from the Charge button
             that produces it in an 820px viewport. Prompt 60 guaranteed that pressing Charge always produces
             an observable outcome and proved it with assertSee(), which is true of the markup wherever it
             renders — to a parser, not to a person. It now renders beside Charge instead.

             But only when there IS a Charge to stand beside: a blocking state replaces the work AND the cart
             column, so in that case the reason has nowhere else to go and belongs here, exactly as the
             original comment argued. Two positions, one partial, never both at once. --}}
        @if (\App\Support\CounterBlocker::rendersInPage($blocker))
            @include('livewire.counter.partials.counter-flash', ['anchor' => 'data-blocked-feedback'])
        @endif

    @endif

    @if ($blocker === \App\Support\CounterBlocker::SEDE)
        <x-counter.blocking-state
            data-blocker="sede"
            icon="📍"
            :heading="$mustChooseLocation ? __('Elige tu sede') : __('Sin sede asignada')"
            :body="$mustChooseLocation ? __('Trabajas en varias sedes. Selecciona en la barra superior en cuál estás.') : __('No tienes ninguna sede activa. Pide a un responsable que te asigne una para vender en barra.')"
        />
    @elseif ($barDisabled)
        {{-- Not a chain precondition: the bar being off for this sede (per-location bar_enabled, prompt 59) is
             a config fact no operator can fix at the counter, so it has no action. It takes the one visual
             language, but it stays out of CounterBlocker — that chain is preconditions, not settings. --}}
        <x-counter.blocking-state
            data-blocker="bar-disabled"
            icon="🚫"
            :heading="__('Barra desactivada en esta sede')"
            :body="__('Un responsable puede activarla desde la ficha de la sede.')"
        />
    @elseif (\App\Support\CounterBlocker::rendersInPage($blocker))
        {{-- The till, the bar's only remaining precondition — it shares the one drawer. Was a red card with a
             dark-red button in the basket column; same reason, said once, in navigation colour. --}}
        <x-counter.blocking-state
            data-blocker="till"
            icon="🧾"
            :heading="__('No hay caja abierta')"
            :body="__('Abre una caja en este terminal antes de cobrar en barra.')"
            :action-label="__('Ir a la caja')"
            :action-href="route('counter.till')"
        />
    @else
        {{-- At lg (1024, the counter's tablet-first width) the basket+Charge (RIGHT) is pinned to a
             dedicated column 2 spanning both rows, so socio (LEFT) + articles (CENTRE) stack in column 1
             with no dead space and the primary action stays top-right. At xl the RIGHT div resets to
             auto-placement for the 3-column sidebar layout. --}}
        {{-- Prompt 176 — the same two panes as the dispensary, for the same measured reason: with three
             articles in the basket `Cobrar` sat 149px below the fold at 1180x820 and 693px below it at
             820x1180. A dispensary that scrolls correctly and a bar that does not is the same
             inconsistency this programme exists to remove.

             The bar has no member step (it serves for cash) and no gram allowance, so its cart has no
             fixed head — the optional socio attribution rides at the top of the scrolling region, above
             the basket it pays for. The column, and the commit at its foot, are identical. --}}
        <div class="flex h-full min-h-0 flex-col gap-4 md:flex-row">

            {{-- ================= SELECTION: the only thing that scrolls ================= --}}
            <div
                data-selection-pane
                class="flex min-h-0 flex-1 flex-col gap-4 md:overflow-y-auto md:pr-1"
            >
            <div class="flex flex-col gap-4" x-data="{ showMisc: false }" x-on:misc-added.window="showMisc = false">
                <section class="rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900">
                    {{-- Prompt 176: wraps rather than clips. At 820 portrait the selection pane is ~470px
                         and title + view toggle + search + manual-line did not fit on one row. --}}
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <h2 class="text-base font-semibold">{{ __('Artículos') }}</h2>
                        {{-- List / grid. GRID is the default for articles — a name and a price fit a tile,
                             which is the case Loyverse describes; the dispensary defaults the other way. --}}
                        <div role="group" aria-label="{{ __('Vista') }}" class="flex w-fit shrink-0 gap-1 self-start rounded-xl border border-line p-1 dark:border-slate-700">
                            @foreach ([['list', __('Lista'), '☰'], ['grid', __('Cuadrícula'), '▦']] as [$mode, $label, $glyph])
                                <button
                                    type="button"
                                    wire:click="setArticleLayout('{{ $mode }}')"
                                    data-layout-option="{{ $mode }}"
                                    aria-label="{{ $label }}"
                                    aria-pressed="{{ $articleLayout === $mode ? 'true' : 'false' }}"
                                    @class([
                                        'inline-flex h-11 w-11 items-center justify-center rounded-lg text-base transition',
                                        'bg-brand text-white' => $articleLayout === $mode,
                                        'text-ink-muted hover:bg-surface-alt dark:text-slate-400 dark:hover:bg-slate-800' => $articleLayout !== $mode,
                                    ])
                                >{{ $glyph }}</button>
                            @endforeach
                        </div>

                        <div class="flex min-w-0 flex-1 items-center gap-2">
                            <input
                                type="text"
                                wire:model.live.debounce.300ms="articleSearch" aria-label="{{ __('Buscar artículo…') }}"
                                autocomplete="off"
                                placeholder="{{ __('Buscar artículo…') }}"
                                class="h-11 w-full min-w-0 rounded-xl border border-line bg-surface px-4 text-sm text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 sm:w-48"
                            >
                            <button type="button" @click="showMisc = true" class="inline-flex h-11 shrink-0 items-center gap-1 rounded-xl border border-brand/40 bg-brand-tint/40 px-3 text-sm font-semibold text-brand transition hover:bg-brand-tint dark:border-brand/40 dark:bg-slate-800 dark:text-slate-100">
                                <span aria-hidden="true">＋</span>{{ __('Importe manual') }}
                            </button>
                        </div>
                    </div>

                    @if (! empty($categories))
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button" wire:click="filterCategory(null)" @class(['inline-flex items-center rounded-full border min-h-11 px-4 text-sm', 'border-brand bg-brand text-white' => $categoryId === null, 'border-line text-ink-muted dark:border-slate-700 dark:text-slate-400' => $categoryId !== null])>{{ __('Todas') }}</button>
                            @foreach ($categories as $category)
                                <button type="button" wire:click="filterCategory('{{ $category['id'] }}')" @class(['inline-flex items-center rounded-full border min-h-11 px-4 text-sm', 'border-brand bg-brand text-white' => $categoryId === $category['id'], 'border-line text-ink-muted dark:border-slate-700 dark:text-slate-400' => $categoryId !== $category['id']])>{{ $category['name'] }}</button>
                            @endforeach
                        </div>
                    @endif

                    @php
                        // A full @php block, not @php(...): an arrow function's `=>` inside the parenthesised
                        // form is a Blade parse error, and the harness happily asserted against the 500 page.
                        $showThumbs = collect($articles)->contains(fn (array $a): bool => filled($a['image_url']));
                    @endphp

                    <div @class([
                        'mt-4',
                        'flex flex-col gap-1.5' => $articleLayout === 'list',
                        'grid gap-3 sm:grid-cols-2 lg:grid-cols-3' => $articleLayout === 'grid',
                    ])>
                        @forelse ($articles as $a)
                            @php $soldOut = $a['stock'] <= 0; @endphp

                            @if ($articleLayout === 'list')
                                {{-- Prompt 193 — a ROW, not the grid tile turned sideways. The old list mode reused
                                     the tile markup with `lg:flex-row`, and the tile's image block is `h-24 w-full`,
                                     so in a row it claimed the whole width: measured 714x106 at 1180x820 with the
                                     name and price crammed into the remainder, and 166px tall below `lg` where the
                                     tile did not rotate at all. Six rows filled the viewport — worse density than
                                     the grid it is an alternative to, which left list mode with no reason to exist.

                                     A row is its own component: fixed small thumbnail, name on ONE line, and the
                                     numbers right-aligned in their own columns so the price and stock scan straight
                                     down the list. `tabular-nums` is what makes that column actually align. --}}
                                <button
                                    type="button"
                                    @if (! $soldOut) wire:click="addArticle('{{ $a['id'] }}')" @endif
                                    @disabled($soldOut)
                                    data-product
                                    data-product-row
                                    @class([
                                        'flex h-[4.25rem] w-full items-center gap-3 rounded-xl border px-3 text-left transition',
                                        'border-line bg-surface hover:border-brand hover:bg-brand-tint/40 dark:border-slate-700 dark:bg-slate-950 dark:hover:border-brand' => ! $soldOut,
                                        'cursor-not-allowed border-dashed border-line bg-surface-alt opacity-60 dark:border-slate-800 dark:bg-slate-900' => $soldOut,
                                    ])
                                >
                                    {{-- The thumbnail column exists only if some article at this sede HAS an image.
                                         None of them does today, and a large empty glyph is a broken-looking gap
                                         rather than a design (prompt 193). Missing photos are the club's to supply;
                                         nothing here fabricates one. --}}
                                    @if ($showThumbs)
                                        <span class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-surface-alt dark:bg-slate-800">
                                            @if ($a['image_url'])
                                                <img src="{{ $a['image_url'] }}" alt="" class="h-full w-full object-cover">
                                            @else
                                                <span class="text-sm font-semibold text-ink-muted dark:text-slate-400">{{ mb_strtoupper(mb_substr($a['name'], 0, 1)) }}</span>
                                            @endif
                                        </span>
                                    @endif

                                    <span data-product-name class="min-w-0 flex-1 truncate font-semibold leading-tight">{{ $a['name'] }}</span>

                                    <span class="shrink-0 text-xs text-ink-muted tabular-nums dark:text-slate-400">
                                        @if ($soldOut)
                                            <span class="inline-flex items-center gap-1 text-error"><span class="h-2 w-2 rounded-full bg-error"></span>{{ __('Agotado') }}</span>
                                        @elseif ($a['low_stock'])
                                            <span class="inline-flex items-center gap-1 text-warning"><span class="h-2 w-2 rounded-full bg-warning"></span>{{ $a['stock'] }}</span>
                                        @else
                                            {{ __('Stock') }}: {{ $a['stock'] }}
                                        @endif
                                    </span>

                                    <span class="w-20 shrink-0 text-right text-sm font-semibold tabular-nums text-brand dark:text-slate-100">{{ $this->money($a['price_cents']) }}</span>
                                </button>
                            @else
                                <button
                                    type="button"
                                    @if (! $soldOut) wire:click="addArticle('{{ $a['id'] }}')" @endif
                                    @disabled($soldOut)
                                    data-product
                                    @class([
                                        'flex flex-col overflow-hidden rounded-xl border text-left transition',
                                        'border-line bg-surface hover:border-brand hover:bg-brand-tint/40 dark:border-slate-700 dark:bg-slate-950 dark:hover:border-brand' => ! $soldOut,
                                        'cursor-not-allowed border-dashed border-line bg-surface-alt opacity-60 dark:border-slate-800 dark:bg-slate-900' => $soldOut,
                                    ])
                                >
                                    <div class="flex h-24 w-full items-center justify-center bg-surface-alt dark:bg-slate-800">
                                        @if ($a['image_url'])
                                            <img src="{{ $a['image_url'] }}" alt="" class="h-full w-full object-cover">
                                        @else
                                            <span class="text-3xl opacity-70">🛒</span>
                                        @endif
                                    </div>
                                    <div class="flex flex-1 flex-col p-3">
                                        <div class="flex items-start justify-between gap-2">
                                            {{-- min-w-0 lets a long name wrap/clamp instead of forcing the row past the
                                                 card's overflow-hidden edge and clipping the shrink-0 price. --}}
                                            <span data-product-name class="min-w-0 font-semibold leading-tight line-clamp-2">{{ $a['name'] }}</span>
                                            <span class="shrink-0 text-sm font-semibold text-brand dark:text-slate-100">{{ $this->money($a['price_cents']) }}</span>
                                        </div>
                                        <div class="mt-2 flex items-center justify-between text-xs">
                                            <span class="text-ink-muted dark:text-slate-400">{{ __('Stock') }}: {{ $a['stock'] }}</span>
                                            @if ($soldOut)
                                                <span class="inline-flex items-center gap-1 text-error"><span class="h-2 w-2 rounded-full bg-error"></span>{{ __('Agotado') }}</span>
                                            @elseif ($a['low_stock'])
                                                <span class="inline-flex items-center gap-1 text-warning"><span class="h-2 w-2 rounded-full bg-warning"></span>{{ __('Stock bajo') }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </button>
                            @endif
                        @empty
                            <p class="col-span-full rounded-xl border border-dashed border-line px-4 py-8 text-center text-sm text-ink-muted dark:border-slate-700 dark:text-slate-400">{{ __('No hay artículos activos en esta sede.') }}</p>
                        @endforelse
                    </div>
                </section>

                {{-- Manual-line entry as an on-demand modal (prompt 126) — opened from the header button, so it is
                     reachable at 1024×768 without scrolling past the catalogue. The reason is ONE TAP for the
                     common cases (categorised + fast) with free text as the fallback. --}}
                <div x-show="showMisc" x-cloak @keydown.escape.window="showMisc = false"
                     class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-label="{{ __('Importe manual') }}">
                    <div @click.outside="showMisc = false" class="w-full max-w-md rounded-2xl border border-line bg-surface p-5 shadow-xl dark:border-slate-800 dark:bg-slate-900">
                        <div class="flex items-center justify-between">
                            <h2 class="text-base font-semibold">{{ __('Importe manual') }}</h2>
                            <button type="button" @click="showMisc = false" aria-label="{{ __('Cerrar') }}" class="flex h-11 w-11 items-center justify-center rounded-lg text-ink-muted hover:bg-black/5 dark:text-slate-400 dark:hover:bg-white/5">✕</button>
                        </div>
                        <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">{{ __('Un concepto fuera de catálogo (no mueve stock). El motivo queda registrado para poder revisarlo después.') }}</p>

                        <div class="mt-3 space-y-3">
                            <div>
                                <label for="misc-desc" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Descripción') }}</label>
                                <input id="misc-desc" type="text" wire:model="miscDescription" autocomplete="off" class="mt-1 h-11 w-full rounded-xl border border-line bg-surface px-3 text-base text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                            </div>
                            <div>
                                <label for="misc-amount" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Importe (€)') }}</label>
                                <input id="misc-amount" type="text" inputmode="decimal" wire:model="miscAmount" autocomplete="off" placeholder="0,00" class="mt-1 h-11 w-full rounded-xl border border-line bg-surface px-3 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                            </div>
                            <div>
                                <label for="misc-ref" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Motivo') }}</label>
                                <div class="mt-1 flex flex-wrap gap-1.5">
                                    @foreach ([__('Artículo sin dar de alta'), __('Precio especial'), __('Evento')] as $reason)
                                        <button type="button" @click="$wire.set('miscReference', @js($reason))" class="rounded-full border border-line px-3 py-1.5 text-sm text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:text-slate-100 dark:hover:bg-slate-800">{{ $reason }}</button>
                                    @endforeach
                                </div>
                                <input id="misc-ref" type="text" wire:model="miscReference" autocomplete="off" placeholder="{{ __('… o escribe un motivo') }}" class="mt-2 h-11 w-full rounded-xl border border-line bg-surface px-3 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                                <p class="mt-1 text-[11px] text-ink-muted dark:text-slate-400">{{ __('Justifica por qué es una línea sin catálogo — se revisa al cerrar la caja.') }}</p>
                            </div>
                            <x-button size="md" class="w-full" wire:click="addMiscLine">{{ __('Añadir importe manual') }}</x-button>
                        </div>
                    </div>
                </div>
            </div>
            </div>

            {{-- ================= CART: fixed. Socio, basket, commit. ================= --}}
            <aside
                data-cart-column
                class="flex min-h-0 shrink-0 flex-col gap-3 md:w-[19rem] lg:w-[21rem]"
            >
                <div class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto">
                {{-- Prompt 193 — per-sede, and NOT rendered when off (not collapsed, not disabled): most bar
                     sales are a coffee for cash, and with this off the cart column opens on the Basket, which
                     is where the operator's attention belongs. The flag governs INPUT only — a socio recorded
                     on an earlier order still shows on its receipt, in the ledger export and in reports. --}}
                @if ($attachSocioEnabled)
                <section class="rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-base font-semibold">{{ __('Socio (opcional)') }}</h2>
                    <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">{{ __('Atribuye un socio para pagar con monedero. Cobrar en efectivo a un invitado también es válido.') }}</p>

                    @if ($member)
                        <div class="mt-3 flex items-start gap-3">
                            <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-brand-tint text-lg font-bold text-brand dark:bg-slate-800 dark:text-slate-200">
                                {{ mb_strtoupper(mb_substr($member->first_name, 0, 1).mb_substr($member->last_name, 0, 1)) }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3 class="truncate font-bold">{{ $member->fullName() }}</h3>
                                <p class="text-sm text-ink-muted dark:text-slate-400">{{ $member->member_no }}</p>
                                <p class="mt-1 text-sm">
                                    <span class="text-ink-muted dark:text-slate-400">{{ __('Monedero') }}:</span>
                                    <span class="font-semibold {{ $walletCents < 0 ? 'text-error' : '' }}">{{ $this->money($walletCents) }}</span>
                                </p>
                            </div>
                            <button type="button" wire:click="clearMember" class="shrink-0 rounded-lg px-2 py-1.5 text-sm text-ink-muted transition hover:bg-black/5 dark:text-slate-400 dark:hover:bg-white/5">{{ __('Quitar') }}</button>
                        </div>
                    @else
                        {{-- Prompt 194 — the SAME lookup as the door and the dispensary. The bar's own name box
                             had no scan affordance, so a card scanned at this counter found nothing; attaching
                             a socio here is optional, so this must not steal the cursor from the basket. --}}
                        <div class="mt-3">
                            @include('livewire.counter.partials.member-lookup', ['autofocus' => false])
                        </div>
                    @endif
                </section>
                @endif

                {{-- Order-level reference (guests / rollout / event) — per-sede, off by default (prompt 193).
                     Same rule as the socio panel: the flag governs input, never display. --}}
                @if ($ticketReferenceEnabled)
                <section class="rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900">
                    <label for="reference" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Referencia del ticket (opcional)') }}</label>
                    <input
                        id="reference"
                        type="text"
                        wire:model.blur="reference"
                        autocomplete="off"
                        placeholder="{{ __('Ej. Invitado, evento…') }}"
                        class="mt-2 h-11 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                    >
                </section>
                @endif

                <section class="rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between">
                        <h2 class="text-base font-semibold">{{ __('Cesta') }}</h2>
                        @if (! empty($basketLines))
                            <button type="button" wire:click="clearBasket" class="inline-flex h-11 min-w-[2.75rem] items-center justify-center rounded-lg px-3 text-sm text-ink-muted hover:bg-black/5 dark:text-slate-400 dark:hover:bg-white/5">{{ __('Vaciar') }}</button>
                        @endif
                    </div>

                    <ul class="mt-3 divide-y divide-line dark:divide-slate-800">
                        @forelse ($basketLines as $line)
                            <li wire:key="line-{{ $line['index'] }}" class="flex items-start justify-between gap-3 py-2.5">
                                <div class="min-w-0">
                                    <p class="truncate font-medium">{{ $line['name'] }}</p>
                                    <p class="text-xs text-ink-muted dark:text-slate-400">
                                        {{ $this->money($line['unit_cents']) }}
                                        @if ($line['type'] === 'misc' && $line['reference'])· <span class="italic">{{ $line['reference'] }}</span>@endif
                                    </p>
                                    <div class="mt-1.5 inline-flex items-center gap-1 rounded-lg border border-line dark:border-slate-700">
                                        <button type="button" wire:click="decrementLine({{ $line['index'] }})" aria-label="{{ __('Menos una unidad') }}" class="flex h-11 w-11 items-center justify-center rounded-l-lg text-lg text-ink-muted transition hover:bg-surface-alt dark:text-slate-400 dark:hover:bg-slate-800">−</button>
                                        <span class="min-w-8 text-center text-sm font-semibold tabular-nums">{{ $line['qty'] }}</span>
                                        <button type="button" wire:click="incrementLine({{ $line['index'] }})" aria-label="{{ __('Más una unidad') }}" class="flex h-11 w-11 items-center justify-center rounded-r-lg text-lg text-ink-muted transition hover:bg-surface-alt dark:text-slate-400 dark:hover:bg-slate-800">+</button>
                                    </div>
                                </div>
                                <div class="flex shrink-0 flex-col items-end gap-2">
                                    <span class="font-semibold tabular-nums">{{ $this->money($line['line_total_cents']) }}</span>
                                    <button type="button" wire:click="removeLine({{ $line['index'] }})" aria-label="{{ __('Quitar de la cesta') }}" class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-ink-muted hover:bg-black/5 dark:text-slate-400 dark:hover:bg-white/5">✕</button>
                                </div>
                            </li>
                        @empty
                            <li class="py-6 text-center text-sm text-ink-muted dark:text-slate-400">{{ __('Cesta vacía. Toca un artículo para añadirlo.') }}</li>
                        @endforelse
                    </ul>

                    {{-- Total --}}
                    <div class="mt-3 flex items-center justify-between rounded-xl bg-surface-alt px-4 py-3 dark:bg-slate-800">
                        <span class="font-semibold">{{ __('Total') }}</span>
                        <span class="text-lg font-bold tabular-nums">{{ $this->money($basketTotalCents) }}</span>
                    </div>

                    {{-- Payment --}}
                    <div class="mt-4 space-y-3">
                        {{-- Wallet (only with a socio attached) — and therefore only when this sede allows one
                             to be attached at all (prompt 193). Offering a tender that can never complete is
                             worse than not offering it, so the field goes rather than sitting permanently
                             disabled. --}}
                        @if ($attachSocioEnabled)
                        <div>
                            <label for="wallet" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Monedero (€)') }}</label>
                            <input
                                id="wallet"
                                type="text"
                                inputmode="decimal"
                                wire:model.live.debounce.400ms="walletInput"
                                @disabled($member === null)
                                autocomplete="off"
                                placeholder="0,00"
                                class="mt-1 h-11 w-full rounded-xl border border-line bg-surface px-3 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                            @if ($member === null)
                                <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">{{ __('Atribuye un socio para pagar con monedero.') }}</p>
                            @endif
                        </div>
                        @endif

                        {{-- Quick cash --}}
                        <div>
                            {{-- A real <label for>, not a loose <p> plus a placeholder: a placeholder disappears on focus and is
                                 not a label (a11y audit), and this is the field that decides what goes in the drawer. --}}
                            <label for="bar-cash-tendered" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Efectivo entregado') }}</label>
                            <div class="mt-1 grid grid-cols-4 gap-2">
                                <button type="button" wire:click="quickCash" class="h-11 rounded-xl border border-line bg-surface text-sm font-semibold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:hover:bg-slate-800">{{ __('Justo') }}</button>
                                <button type="button" wire:click="quickCash(500)" class="h-11 rounded-xl border border-line bg-surface text-sm font-semibold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:hover:bg-slate-800">€5</button>
                                <button type="button" wire:click="quickCash(1000)" class="h-11 rounded-xl border border-line bg-surface text-sm font-semibold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:hover:bg-slate-800">€10</button>
                                <button type="button" wire:click="quickCash(2000)" class="h-11 rounded-xl border border-line bg-surface text-sm font-semibold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:hover:bg-slate-800">€20</button>
                            </div>
                            <input
                                id="bar-cash-tendered"
                                type="text"
                                inputmode="decimal"
                                wire:model.live.debounce.400ms="cashTendered"
                                autocomplete="off"
                                placeholder="{{ __('Efectivo entregado (€)') }}"
                                class="mt-2 h-11 w-full rounded-xl border border-line bg-surface px-3 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                        </div>

                        <dl class="space-y-1 rounded-xl bg-surface-alt px-4 py-3 text-sm dark:bg-slate-800">
                            <div class="flex items-center justify-between">
                                <dt class="text-ink-muted dark:text-slate-400">{{ __('A cobrar en efectivo') }}</dt>
                                <dd class="font-semibold tabular-nums">{{ $this->money($cashPostedCents) }}</dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt class="text-ink-muted dark:text-slate-400">{{ __('Monedero') }}</dt>
                                <dd class="font-semibold tabular-nums">{{ $this->money($walletAppliedCents) }}</dd>
                            </div>
                            <div class="flex items-center justify-between border-t border-line pt-1 dark:border-slate-700">
                                <dt class="font-medium">{{ __('Cambio') }}</dt>
                                <dd class="text-base font-bold tabular-nums {{ $changeDueCents > 0 ? 'text-success' : '' }}">{{ $this->money($changeDueCents) }}</dd>
                            </div>
                            @if ($member)
                                <div class="flex items-center justify-between text-xs text-ink-muted dark:text-slate-400">
                                    <dt>{{ __('Monedero tras la venta') }}</dt>
                                    <dd class="font-medium {{ $projectedWalletCents < 0 ? 'text-error' : '' }}">{{ $this->money($projectedWalletCents) }}</dd>
                                </div>
                            @endif
                        </dl>
                    </div>

                    {{-- Prompt 41's colocated copy stood here and was REMOVED by prompt 199. It was not
                         wrong — it was first — but 193 added its own colocated block beside Charge without
                         taking this one out, so every outcome rendered twice, in two live regions with
                         identical text. A screen reader announced each refusal twice, which is worse than
                         the 650px distance 193 set out to fix. The surviving one is below, next to the
                         control: same message, same mechanism, rendered once. --}}
                </section>

                {{-- Just committed → ticket + void affordance.

                     Prompt 202 took the CONFIRMATION out of this block. It used to be success-green and
                     headed *"Última venta registrada"*, which is a second "it worked" on a screen that already
                     has one beside Charge — the same defect 199 fixed one block up, in a different costume.
                     What is left is what only this block can offer: the ticket, and the void. It is a LABEL
                     over two affordances now, not an announcement, so it is neutral and carries no live
                     region. --}}
                @if ($lastOrderId)
                    <section class="rounded-2xl border border-line bg-surface-alt p-4 dark:border-slate-700 dark:bg-slate-800/50">
                        <p class="text-sm font-semibold text-ink-muted dark:text-slate-400">{{ __('Última venta') }}</p>
                        <div class="mt-3 flex flex-col gap-2">
                            <a href="{{ route('counter.bar.receipt', $lastOrderId) }}" target="_blank" rel="noopener" class="inline-flex h-11 items-center justify-center rounded-xl border border-line bg-surface px-4 text-sm font-semibold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">{{ __('Ver / imprimir ticket') }}</a>

                            @if ($canVoid)
                                <div class="rounded-xl border border-line bg-surface p-3 dark:border-slate-700 dark:bg-slate-900">
                                    <label class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Anular esta venta') }}</label>
                                    <textarea wire:model="voidReason" rows="2" placeholder="{{ __('Motivo de la anulación (queda registrado)') }}" class="mt-1 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm focus:border-error focus:outline-none focus:ring-2 focus:ring-error/30 dark:border-slate-700 dark:bg-slate-950"></textarea>
                                    <button type="button" wire:click="voidLast" wire:confirm="{{ __('¿Anular la venta? Se revertirán stock y monedero.') }}" class="mt-2 h-11 w-full rounded-lg border border-error/40 bg-error/10 text-sm font-semibold text-error transition hover:bg-error/20">{{ __('Anular') }}</button>
                                </div>
                            @endif
                        </div>
                    </section>
                @endif
                </div>

                {{-- BOTTOM — the commit, at the foot of the column, with its OUTCOME beside it. --}}
                <div class="shrink-0">
                    {{-- The answer to "I pressed Charge", beside Charge (prompts 192/193). --}}
                    @include('livewire.counter.partials.counter-flash', ['anchor' => 'data-commit-feedback'])

                    {{-- Commit — disabled ONLY when offline (prompt 60). The offline banner above is driven
                         by the SAME `online`, so a disabled button always shows its reason. Every other
                         blocked state (no till, empty basket, wallet-without-socio…) stays CLICKABLE and
                         commit() flashes its reason into the colocated block above — never a silent dead control. --}}
                    <button
                        type="button"
                        wire:click="commitOrder"
                        data-commit-action
                        wire:loading.attr="disabled"
                        wire:target="commitOrder"
                        x-bind:disabled="! online"
                        class="mt-4 h-16 w-full rounded-xl bg-brand text-lg font-bold text-white transition hover:bg-brand-dark focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="commitOrder">{{ __('Cobrar') }}</span>
                        <span wire:loading wire:target="commitOrder">{{ __('Cobrando…') }}</span>
                    </button>
                </div>
            </aside>
        </div>
    @endif
@endif
</div>

{{-- Prompt 23: flag unsaved counter work so the header's Administración / Log out controls confirm before
     leaving. `dirty` only — NOT `volatile` (prompt 206): this basket is session-backed (PersistsBasket), so
     it survives the trip to the hub and back, and Home must not warn about a loss that cannot happen. --}}
@script
<script>
    const sync = () => { if (window.Alpine?.store('counter')) window.Alpine.store('counter').dirty = (($wire.basket?.length ?? 0) > 0); };
    $wire.$watch('basket', sync);
    sync();
</script>
@endscript
