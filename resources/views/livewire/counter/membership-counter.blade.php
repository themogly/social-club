{{-- Socios — the counter membership tab (prompt 127): find a member, see what's owed, collect a fee. Thin
     shell over the shared fee-collection concern (RecordFeePayment). Cash reconciles against the open till. --}}
<div>
    @include('livewire.counter.partials.counter-surface')

    @if (! $this->handoverActive())

    @if ($noLocation)
        <div class="rounded-2xl border border-line bg-surface p-8 text-center dark:border-slate-800 dark:bg-slate-900">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-surface-alt text-2xl dark:bg-slate-800">📍</div>
            <h2 class="mt-4 text-lg font-semibold">{{ $mustChooseLocation ? __('Elige tu sede') : __('Sin sede asignada') }}</h2>
            <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">{{ $mustChooseLocation ? __('Trabajas en varias sedes. Selecciona en la barra superior en cuál estás.') : __('No tienes ninguna sede activa. Pide a un responsable que te asigne una.') }}</p>
        </div>
    @else
        @if ($flashMessage)
            <div wire:key="flash" role="{{ $flashType === 'error' ? 'alert' : 'status' }}"
                @class([
                    'mb-4 flex items-center justify-between gap-3 rounded-xl border px-4 py-3 text-sm font-medium',
                    'border-success/30 bg-success/10 text-success' => $flashType === 'success',
                    'border-warning/30 bg-warning/10 text-warning' => $flashType === 'warning',
                    'border-error/30 bg-error/10 text-error' => $flashType === 'error',
                ])>
                <span>{{ $flashMessage }}</span>
                <button type="button" wire:click="$set('flashMessage', null)" aria-label="{{ __('Descartar aviso') }}" class="flex h-11 w-11 items-center justify-center rounded-md opacity-70 hover:opacity-100">✕</button>
            </div>
        @endif

        @unless ($openTill)
            <div class="mb-4 rounded-xl border border-warning/40 bg-warning/10 px-4 py-3 text-sm text-warning">
                {{ __('No hay caja abierta en esta sede: solo puedes cobrar cuotas con monedero hasta que se abra una.') }}
            </div>
        @endunless

        <div class="mx-auto max-w-xl">
            <section class="rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900">
                <h1 class="text-base font-semibold">{{ __('Cobro de cuota') }}</h1>

                @if ($feeMember)
                    <div class="mt-3 flex items-start justify-between gap-3 rounded-xl bg-surface-alt p-3 dark:bg-slate-800">
                        <div class="min-w-0">
                            <p class="truncate font-semibold">{{ $feeMember->fullName() }}</p>
                            <p class="text-sm text-ink-muted dark:text-slate-400">{{ $feeMember->member_no }}</p>
                            @if ($membership)
                                <p class="mt-1 text-sm">
                                    {{ __('Cuota') }}: <span class="font-medium">{{ $this->money($membership->fee_cents->cents) }}</span>
                                    @if ($membership->expires_at)
                                        · {{ __('Vence') }} {{ $membership->expires_at->format('d/m/Y') }}
                                    @endif
                                </p>
                                <p class="mt-0.5 text-sm">
                                    {{ __('Pendiente') }}:
                                    <span class="font-semibold {{ ($owedCents ?? 0) > 0 ? 'text-warning' : 'text-success' }}">{{ $this->money($owedCents ?? 0) }}</span>
                                </p>
                            @else
                                <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">{{ __('Sin membresía activa en esta sede.') }}</p>
                            @endif
                        </div>
                        <button type="button" wire:click="clearFeeMember" class="flex h-11 shrink-0 items-center rounded-lg px-3 text-sm text-ink-muted transition hover:bg-black/5 dark:text-slate-400 dark:hover:bg-white/5">{{ __('Cambiar') }}</button>
                    </div>

                    @if (($owedCents ?? 0) > 0)
                        <form wire:submit="collectFee" class="mt-4 space-y-3">
                            <div>
                                <label for="fee-amount" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Importe (€)') }}</label>
                                <input id="fee-amount" type="text" inputmode="decimal" wire:model="feeAmount" autocomplete="off" placeholder="{{ number_format(($owedCents ?? 0) / 100, 2, ',', '') }}" class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                                <p class="mt-1 text-[11px] text-ink-muted dark:text-slate-500">{{ __('Puedes cobrar el total o una parte.') }}</p>
                            </div>
                            <div>
                                <span class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Método') }}</span>
                                <div class="mt-1 grid grid-cols-2 gap-2">
                                    <button type="button" wire:click="$set('feeMethod', 'CASH')" @class(['h-11 rounded-xl border text-sm font-semibold', 'border-brand bg-brand text-white' => $feeMethod === 'CASH', 'border-line text-ink dark:border-slate-700 dark:text-slate-100' => $feeMethod !== 'CASH'])>{{ __('Efectivo') }}</button>
                                    <button type="button" wire:click="$set('feeMethod', 'WALLET')" @class(['h-11 rounded-xl border text-sm font-semibold', 'border-brand bg-brand text-white' => $feeMethod === 'WALLET', 'border-line text-ink dark:border-slate-700 dark:text-slate-100' => $feeMethod !== 'WALLET'])>{{ __('Monedero') }}</button>
                                </div>
                            </div>
                            <button type="submit" wire:loading.attr="disabled" wire:target="collectFee" class="h-12 w-full rounded-xl bg-brand text-base font-semibold text-white transition hover:bg-brand-dark focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:opacity-60">{{ __('Cobrar cuota') }}</button>
                        </form>
                    @endif
                @else
                    <label for="fee-search" class="mt-3 block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Buscar socio (nombre o nº)') }}</label>
                    <input id="fee-search" type="text" autofocus wire:model.live.debounce.300ms="feeSearch" autocomplete="off" placeholder="{{ __('Buscar socio (nombre o nº)') }}" class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">

                    @if ($feeResults !== null)
                        <ul class="mt-2 divide-y divide-line overflow-hidden rounded-xl border border-line dark:divide-slate-800 dark:border-slate-800">
                            @forelse ($feeResults as $result)
                                <li>
                                    <button type="button" wire:click="selectFeeMember('{{ $result->id }}')" class="flex w-full items-center justify-between gap-3 bg-surface px-4 py-3 text-left transition hover:bg-surface-alt dark:bg-slate-900 dark:hover:bg-slate-800">
                                        <span class="min-w-0"><span class="block truncate font-medium">{{ $result->fullName() }}</span><span class="block text-sm text-ink-muted dark:text-slate-400">{{ $result->member_no }}</span></span>
                                    </button>
                                </li>
                            @empty
                                <li class="px-4 py-3 text-sm text-ink-muted dark:text-slate-400">{{ __('Sin resultados.') }}</li>
                            @endforelse
                        </ul>
                    @endif
                @endif
            </section>
        </div>
    @endif
@endif
</div>
