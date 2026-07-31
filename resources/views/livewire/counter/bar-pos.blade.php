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
            this.$watch(() => JSON.stringify($wire.basket), value => { try { localStorage.setItem('bar.basket', value); } catch (e) {} });
        },
    }"
>
    @include('livewire.counter.partials.operator-strip')

    @if ($noLocation)
        {{-- Intentional empty state: an operator with no assigned sede. Still a 200. --}}
        <div class="rounded-2xl border border-line bg-surface p-8 text-center dark:border-slate-800 dark:bg-slate-900">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-surface-alt text-2xl dark:bg-slate-800">📍</div>
            <h2 class="mt-4 text-lg font-semibold">{{ __('Sin sede asignada') }}</h2>
            <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">
                {{ __('No tienes ninguna sede activa. Pide a un responsable que te asigne una para vender en barra.') }}
            </p>
        </div>
    @elseif ($barDisabled)
        {{-- The bar is turned off for this sede (per-location bar_enabled, prompt 59). Still a 200. --}}
        <div class="rounded-2xl border border-line bg-surface p-8 text-center dark:border-slate-800 dark:bg-slate-900">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-surface-alt text-2xl dark:bg-slate-800">🚫</div>
            <h2 class="mt-4 text-lg font-semibold">{{ __('Barra desactivada en esta sede') }}</h2>
            <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">
                {{ __('Un responsable puede activarla desde la ficha de la sede.') }}
            </p>
        </div>
    @else
        {{-- Offline banner — unmistakable, fail closed. --}}
        <div x-show="! online" x-cloak role="alert" aria-live="assertive" class="mb-4 flex items-center gap-3 rounded-xl border border-error/40 bg-error/10 px-4 py-3 text-sm font-semibold text-error">
            <span class="text-lg">⚠️</span>
            <span>{{ __('Sin conexión. No se puede registrar ninguna venta; la cesta se conserva y se reactivará al reconectar.') }}</span>
        </div>

        {{-- Flash --}}
        @if ($flashMessage)
            <div
                wire:key="flash"
                role="{{ $flashType === 'error' ? 'alert' : 'status' }}"
                aria-live="{{ $flashType === 'error' ? 'assertive' : 'polite' }}"
                @class([
                    'mb-4 flex items-center justify-between gap-3 rounded-xl border px-4 py-3 text-sm font-medium',
                    'border-success/30 bg-success/10 text-success' => $flashType === 'success',
                    'border-warning/30 bg-warning/10 text-warning' => $flashType === 'warning',
                    'border-error/30 bg-error/10 text-error' => $flashType === 'error',
                ])
            >
                <span>{{ $flashMessage }}</span>
                <button type="button" wire:click="$set('flashMessage', null)" aria-label="{{ __('Descartar aviso') }}" class="shrink-0 rounded-md px-2 py-1 opacity-70 hover:opacity-100">✕</button>
            </div>
        @endif

        {{-- At lg (1024, the counter's tablet-first width) the basket+Charge (RIGHT) is pinned to a
             dedicated column 2 spanning both rows, so socio (LEFT) + articles (CENTRE) stack in column 1
             with no dead space and the primary action stays top-right. At xl the RIGHT div resets to
             auto-placement for the 3-column sidebar layout. --}}
        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start xl:grid-cols-[19rem_minmax(0,1fr)_21rem]">

            {{-- ================= LEFT: the socio (OPTIONAL) ================= --}}
            <div class="flex flex-col gap-4">
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
                        <div class="mt-3">
                            <label for="member-search" class="sr-only">{{ __('Buscar socio por nombre / nº de socio') }}</label>
                            <input
                                id="member-search"
                                type="text"
                                wire:model.live.debounce.300ms="search"
                                autocomplete="off"
                                placeholder="{{ __('Buscar socio (nombre o nº)') }}"
                                class="h-11 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >

                            @if ($searchResults !== null)
                                <ul class="mt-2 divide-y divide-line overflow-hidden rounded-xl border border-line dark:divide-slate-800 dark:border-slate-800">
                                    @forelse ($searchResults as $result)
                                        <li>
                                            <button type="button" wire:click="selectMember('{{ $result->id }}')" class="flex w-full items-center justify-between gap-3 bg-surface px-4 py-3 text-left transition hover:bg-surface-alt dark:bg-slate-900 dark:hover:bg-slate-800">
                                                <span class="font-medium">{{ $result->fullName() }}</span>
                                                <span class="text-sm text-ink-muted dark:text-slate-400">{{ $result->member_no }}</span>
                                            </button>
                                        </li>
                                    @empty
                                        <li class="bg-surface px-4 py-3 text-sm text-ink-muted dark:bg-slate-900 dark:text-slate-400">{{ __('Sin resultados.') }}</li>
                                    @endforelse
                                </ul>
                            @endif
                        </div>
                    @endif
                </section>

                {{-- Order-level reference (guests / rollout / event). --}}
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
            </div>

            {{-- ================= CENTRE: article grid + misc line ================= --}}
            <div class="flex flex-col gap-4">
                <section class="rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <h2 class="text-base font-semibold">{{ __('Artículos') }}</h2>
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="articleSearch" aria-label="{{ __('Buscar artículo…') }}"
                            autocomplete="off"
                            placeholder="{{ __('Buscar artículo…') }}"
                            class="h-10 w-full rounded-xl border border-line bg-surface px-4 text-sm text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 sm:w-56"
                        >
                    </div>

                    @if (! empty($categories))
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button" wire:click="filterCategory(null)" @class(['rounded-full border px-3 py-1 text-sm', 'border-brand bg-brand text-white' => $categoryId === null, 'border-line text-ink-muted dark:border-slate-700 dark:text-slate-400' => $categoryId !== null])>{{ __('Todas') }}</button>
                            @foreach ($categories as $category)
                                <button type="button" wire:click="filterCategory('{{ $category['id'] }}')" @class(['rounded-full border px-3 py-1 text-sm', 'border-brand bg-brand text-white' => $categoryId === $category['id'], 'border-line text-ink-muted dark:border-slate-700 dark:text-slate-400' => $categoryId !== $category['id']])>{{ $category['name'] }}</button>
                            @endforeach
                        </div>
                    @endif

                    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @forelse ($articles as $a)
                            @php $soldOut = $a['stock'] <= 0; @endphp
                            <button
                                type="button"
                                @if (! $soldOut) wire:click="addArticle('{{ $a['id'] }}')" @endif
                                @disabled($soldOut)
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
                                             card's overflow-hidden edge and clipping the shrink-0 price (e.g. "Mechero €1,00"). --}}
                                        <span class="min-w-0 font-semibold leading-tight line-clamp-2">{{ $a['name'] }}</span>
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
                        @empty
                            <p class="col-span-full rounded-xl border border-dashed border-line px-4 py-8 text-center text-sm text-ink-muted dark:border-slate-700 dark:text-slate-400">{{ __('No hay artículos activos en esta sede.') }}</p>
                        @endforelse
                    </div>
                </section>

                {{-- Miscellaneous / quick-amount line (a reference is REQUIRED). --}}
                <section class="rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-base font-semibold">{{ __('Importe manual') }}</h2>
                    <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">{{ __('Un concepto fuera de catálogo. La referencia es obligatoria.') }}</p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label for="misc-desc" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Descripción') }}</label>
                            <input id="misc-desc" type="text" wire:model="miscDescription" autocomplete="off" class="mt-1 h-11 w-full rounded-xl border border-line bg-surface px-3 text-base text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                        </div>
                        <div>
                            <label for="misc-amount" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Importe (€)') }}</label>
                            <input id="misc-amount" type="text" inputmode="decimal" wire:model="miscAmount" autocomplete="off" placeholder="0,00" class="mt-1 h-11 w-full rounded-xl border border-line bg-surface px-3 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                        </div>
                        <div>
                            <label for="misc-ref" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Referencia (obligatoria)') }}</label>
                            <input id="misc-ref" type="text" wire:model="miscReference" autocomplete="off" class="mt-1 h-11 w-full rounded-xl border border-line bg-surface px-3 text-base text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                        </div>
                    </div>
                    <button type="button" wire:click="addMiscLine" class="mt-3 h-11 w-full rounded-xl border border-brand/40 bg-brand-tint/40 text-sm font-semibold text-brand transition hover:bg-brand-tint dark:border-brand/40 dark:bg-slate-800 dark:text-slate-100">{{ __('Añadir importe manual') }}</button>
                </section>
            </div>

            {{-- ================= RIGHT: the basket + payment ================= --}}
            <div class="flex flex-col gap-4 lg:col-start-2 lg:row-start-1 lg:row-span-2 xl:col-auto xl:row-auto">
                {{-- No open till → unmistakable, blocks commit (the bar shares the one drawer). --}}
                @unless ($openTill)
                    <div class="rounded-2xl border border-error/40 bg-error/10 p-4 text-sm">
                        <p class="font-semibold text-error">{{ __('No hay caja abierta') }}</p>
                        <p class="mt-1 text-error/90">{{ __('Abre una caja en este terminal antes de cobrar en barra.') }}</p>
                        <a href="{{ route('counter.till') }}" wire:navigate class="mt-3 inline-flex h-10 items-center rounded-lg bg-error px-4 text-sm font-semibold text-white transition hover:opacity-90">{{ __('Ir a la caja') }}</a>
                    </div>
                @endunless

                <section class="rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between">
                        <h2 class="text-base font-semibold">{{ __('Cesta') }}</h2>
                        @if (! empty($basketLines))
                            <button type="button" wire:click="clearBasket" class="rounded-lg px-2 py-1 text-sm text-ink-muted hover:bg-black/5 dark:text-slate-400 dark:hover:bg-white/5">{{ __('Vaciar') }}</button>
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
                                        <button type="button" wire:click="decrementLine({{ $line['index'] }})" aria-label="{{ __('Menos una unidad') }}" class="flex h-8 w-8 items-center justify-center rounded-l-lg text-lg text-ink-muted transition hover:bg-surface-alt dark:text-slate-400 dark:hover:bg-slate-800">−</button>
                                        <span class="min-w-8 text-center text-sm font-semibold tabular-nums">{{ $line['qty'] }}</span>
                                        <button type="button" wire:click="incrementLine({{ $line['index'] }})" aria-label="{{ __('Más una unidad') }}" class="flex h-8 w-8 items-center justify-center rounded-r-lg text-lg text-ink-muted transition hover:bg-surface-alt dark:text-slate-400 dark:hover:bg-slate-800">+</button>
                                    </div>
                                </div>
                                <div class="flex shrink-0 flex-col items-end gap-2">
                                    <span class="font-semibold tabular-nums">{{ $this->money($line['line_total_cents']) }}</span>
                                    <button type="button" wire:click="removeLine({{ $line['index'] }})" aria-label="{{ __('Quitar de la cesta') }}" class="rounded-md px-2 py-1 text-ink-muted hover:bg-black/5 dark:text-slate-400 dark:hover:bg-white/5">✕</button>
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
                        {{-- Wallet (only with a socio attached). --}}
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
                                <p class="mt-1 text-xs text-ink-muted dark:text-slate-500">{{ __('Atribuye un socio para pagar con monedero.') }}</p>
                            @endif
                        </div>

                        {{-- Quick cash --}}
                        <div>
                            <p class="text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Efectivo entregado') }}</p>
                            <div class="mt-1 grid grid-cols-4 gap-2">
                                <button type="button" wire:click="quickCash" class="h-11 rounded-xl border border-line bg-surface text-sm font-semibold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:hover:bg-slate-800">{{ __('Justo') }}</button>
                                <button type="button" wire:click="quickCash(500)" class="h-11 rounded-xl border border-line bg-surface text-sm font-semibold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:hover:bg-slate-800">€5</button>
                                <button type="button" wire:click="quickCash(1000)" class="h-11 rounded-xl border border-line bg-surface text-sm font-semibold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:hover:bg-slate-800">€10</button>
                                <button type="button" wire:click="quickCash(2000)" class="h-11 rounded-xl border border-line bg-surface text-sm font-semibold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:hover:bg-slate-800">€20</button>
                            </div>
                            <input
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

                    {{-- Colocated confirmation (prompt 41): the same flash ALSO renders here, in the
                         basket column at the point of action, so a charge (success OR error) is
                         unmistakable without scrolling back up to the page-top banner. Same
                         $flashMessage/$flashType mechanism, so it covers cash, wallet and every error. --}}
                    @if ($flashMessage)
                        <div
                            wire:key="flash-basket"
                            role="{{ $flashType === 'error' ? 'alert' : 'status' }}"
                            aria-live="{{ $flashType === 'error' ? 'assertive' : 'polite' }}"
                            @class([
                                'mt-4 rounded-xl border px-4 py-3 text-sm font-semibold',
                                'border-success/30 bg-success/10 text-success' => $flashType === 'success',
                                'border-warning/30 bg-warning/10 text-warning' => $flashType === 'warning',
                                'border-error/30 bg-error/10 text-error' => $flashType === 'error',
                            ])
                        >
                            {{ $flashMessage }}
                        </div>
                    @endif

                    {{-- Commit — disabled ONLY when offline (prompt 60). The offline banner above is driven
                         by the SAME `online`, so a disabled button always shows its reason. Every other
                         blocked state (no till, empty basket, wallet-without-socio…) stays CLICKABLE and
                         commit() flashes its reason into the colocated block above — never a silent dead control. --}}
                    <button
                        type="button"
                        wire:click="commit"
                        wire:loading.attr="disabled"
                        wire:target="commit"
                        x-bind:disabled="! online"
                        class="mt-4 h-16 w-full rounded-xl bg-brand text-lg font-bold text-white transition hover:bg-brand-dark focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="commit">{{ __('Cobrar') }}</span>
                        <span wire:loading wire:target="commit">{{ __('Cobrando…') }}</span>
                    </button>
                </section>

                {{-- Just committed → ticket + void affordance. --}}
                @if ($lastOrderId)
                    <section class="rounded-2xl border border-success/30 bg-success/5 p-4">
                        <p class="text-sm font-semibold text-success">{{ __('Última venta registrada') }}</p>
                        <div class="mt-3 flex flex-col gap-2">
                            <a href="{{ route('counter.bar.receipt', $lastOrderId) }}" target="_blank" rel="noopener" class="inline-flex h-11 items-center justify-center rounded-xl border border-line bg-surface px-4 text-sm font-semibold text-ink transition hover:bg-surface-alt dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">{{ __('Ver / imprimir ticket') }}</a>

                            @if ($canVoid)
                                <div class="rounded-xl border border-line bg-surface p-3 dark:border-slate-700 dark:bg-slate-900">
                                    <label class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Anular esta venta') }}</label>
                                    <textarea wire:model="voidReason" rows="2" placeholder="{{ __('Motivo de la anulación (queda registrado)') }}" class="mt-1 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm focus:border-error focus:outline-none focus:ring-2 focus:ring-error/30 dark:border-slate-700 dark:bg-slate-950"></textarea>
                                    <button type="button" wire:click="voidLast" wire:confirm="{{ __('¿Anular la venta? Se revertirán stock y monedero.') }}" class="mt-2 h-10 w-full rounded-lg border border-error/40 bg-error/10 text-sm font-semibold text-error transition hover:bg-error/20">{{ __('Anular') }}</button>
                                </div>
                            @endif
                        </div>
                    </section>
                @endif
            </div>
        </div>
    @endif
</div>

{{-- Prompt 23: flag unsaved counter work so the header's Panel/Log out confirm before leaving. --}}
@script
<script>
    const sync = () => { if (window.Alpine?.store('counter')) window.Alpine.store('counter').dirty = (($wire.basket?.length ?? 0) > 0); };
    $wire.$watch('basket', sync);
    sync();
</script>
@endscript
