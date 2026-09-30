{{-- Prompt 330 — the pending-applications bell (badge hidden at zero) and, under the top bar, the banner for an arrival this
     session has not seen. Its own component: the 15 s poll re-renders only this. The banner sits in the page's flow under the
     bar, so it never covers anything, and nothing here takes focus. --}}
<div wire:poll.15s="check" @if ($visible) data-pending-applications-bell @endif class="contents">
    @if ($visible && $count > 0)
        {{-- The bell: a way to Socios' pending list for a reviewer, a plain indicator otherwise. The count is in the name. --}}
        <{{ $canReview ? 'button' : 'span' }} @if ($canReview) type="button" wire:click="review" @else role="img" @endif
            aria-label="{{ $label }}" title="{{ $label }}"
            class="relative inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg px-3 text-warning transition hover:bg-warning/10">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/></svg>
            <span data-bell-count aria-hidden="true" class="absolute right-0.5 top-0.5 min-w-5 rounded-full bg-warning-fill px-1 text-center text-xs font-bold leading-5 text-white">{{ $count }}</span>
        </{{ $canReview ? 'button' : 'span' }}>
    @endif

    {{-- Into the layout's static slot under the top bar (`#counter-notices`, a live region): in the page's flow, so it
         covers nothing — not the member at the top of the cart, not the commit button, not the keypad. --}}
    @teleport('#counter-notices')
        <div>
            @if ($visible && $confirming)
                {{-- A review asked for with a basket in progress (from the banner or the bell): the basket is kept, say so first. --}}
                <div data-bell-confirming class="mx-4 mt-3 rounded-xl bg-surface dark:bg-slate-900 sm:mx-6">
                    <div class="flex flex-wrap items-center justify-end gap-3 rounded-xl border border-warning/50 bg-warning/10 px-4 py-3">
                        <p class="basis-full text-sm text-ink dark:text-slate-100">{{ __('Tienes una cesta a medias: se guarda y la encontrarás al volver.') }}</p>
                        <x-button size="sm" variant="secondary" wire:click="cancelReview">{{ __('Cancelar') }}</x-button>
                        <x-button size="sm" data-bell-confirm wire:click="confirmReview">{{ __('Ir a revisar') }}</x-button>
                    </div>
                </div>
            @elseif ($visible && $banner !== [])
                <div data-applications-banner class="mx-4 mt-3 rounded-xl bg-surface dark:bg-slate-900 sm:mx-6">
                    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-warning/50 bg-warning/10 px-4 py-3">
                        <p class="min-w-40 flex-1 text-sm text-ink dark:text-slate-100">
                            @if (count($banner) === 1)
                                <strong class="font-semibold">{{ __('Nueva solicitud: :name', ['name' => $banner[0]['name']]) }}</strong>
                                <span class="text-ink-muted dark:text-slate-400">— {{ __('enviada :ago', ['ago' => $banner[0]['ago']]) }}</span>
                            @else
                                <strong class="font-semibold">{{ __(':count nuevas solicitudes', ['count' => count($banner)]) }}</strong>
                            @endif
                        </p>
                        <div class="ml-auto flex gap-2">
                            <x-button size="sm" variant="secondary" data-bell-later wire:click="later">{{ $canReview ? __('Luego') : __('Avisa a un responsable') }}</x-button>
                            @if ($canReview && count($banner) === 1)
                                <x-button size="sm" data-bell-review wire:click="review('{{ $banner[0]['id'] }}')">{{ __('Revisar y aprobar') }}</x-button>
                            @elseif ($canReview)
                                <x-button size="sm" data-bell-review wire:click="review">{{ __('Revisar solicitudes') }}</x-button>
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endteleport
</div>
