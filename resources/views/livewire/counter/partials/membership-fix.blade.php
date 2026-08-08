{{-- 203's membership fix, on every screen that shows the problem it fixes (prompt 211).

     203 built this panel — the three cases, the tier picker, the enrol/renew buttons and the refusal for an
     operator without `membership.enrol` — and put it on Socios. The door and the dispensary render the same
     verdict, from the same resolver, and showed the same "sin membresía activa en esta sede" with nothing to
     press. Reported twice, once for each.

     So it is one partial now, reading `OpensMemberships::membershipFix()`, included by all three. Not a copy
     per screen: three copies of a panel that enrols members is exactly the drift CLAUDE.md forbids, and the
     one that drifted would be the one deciding a register fact.

     Case 3 — active at another sede — ENROLS a second membership and never transfers. The other sede loses
     nothing; the wording says so. Fee overrides, tier changes, suspensions and transfers have not moved. --}}
@php($fix = $this->membershipFix())
@if ($fix !== null)
    <div data-membership-fix class="mt-3 rounded-xl border border-brand/30 bg-brand-tint p-3 dark:border-slate-700 dark:bg-slate-800">
        @if (! $this->canOpenMembership())
            {{-- A clear refusal, never a dead panel: the operator is told who CAN do it rather than being
                 shown a button that will not work. --}}
            <p data-membership-fix-denied class="text-sm text-ink-muted dark:text-slate-400">
                {{ __('Este socio necesita una membresía en esta sede. Pídeselo a un responsable: no tienes permiso para darla de alta.') }}
            </p>
        @elseif ($fix['case'] === 'lapsed_here')
            <p class="text-sm font-medium">{{ __('Su membresía en esta sede ha vencido.') }}</p>
            <p class="mt-0.5 text-xs text-ink-muted dark:text-slate-400">
                {{ __('Cuota') }}: {{ $fix['lapsed']?->tier?->name ?? '—' }}
                @if ($fix['lapsed']?->expires_at)
                    · {{ __('Venció') }} {{ $fix['lapsed']->expires_at->format('d/m/Y') }}
                @endif
            </p>
            <button
                type="button"
                wire:click="renewMembership"
                data-membership-renew
                class="mt-3 inline-flex h-11 w-full items-center justify-center rounded-xl bg-brand px-4 text-sm font-semibold text-white transition hover:bg-brand-dark focus:outline-none focus:ring-2 focus:ring-brand/40"
            >{{ __('Renovar membresía') }}</button>
        @else
            <p class="text-sm font-medium">
                {{ $fix['elsewhere']->isNotEmpty() ? __('Es socio del club, pero no de esta sede.') : __('Todavía no es socio de ninguna sede.') }}
            </p>
            @if ($fix['elsewhere']->isNotEmpty())
                <p class="mt-0.5 text-xs text-ink-muted dark:text-slate-400">
                    {{ __('Su membresía en la otra sede no cambia: aquí se añade una nueva.') }}
                </p>
            @endif

            <label for="open-tier-{{ $fix['member']->id }}" class="mt-3 block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Cuota') }}</label>
            <select
                id="open-tier-{{ $fix['member']->id }}"
                wire:model="openTierId"
                data-membership-tier
                class="mt-1 h-11 w-full rounded-xl border border-line bg-surface px-3 text-sm text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
            >
                <option value="">{{ __('Elige una cuota') }}</option>
                @foreach ($fix['tiers'] as $tier)
                    <option value="{{ $tier->id }}">{{ $tier->name }} · {{ $this->money($tier->default_fee_cents->cents) }}</option>
                @endforeach
            </select>

            <button
                type="button"
                wire:click="enrolAtThisSede"
                data-membership-enrol
                class="mt-3 inline-flex h-11 w-full items-center justify-center rounded-xl bg-brand px-4 text-sm font-semibold text-white transition hover:bg-brand-dark focus:outline-none focus:ring-2 focus:ring-brand/40"
            >{{ $fix['elsewhere']->isNotEmpty() ? __('Dar de alta también en esta sede') : __('Dar de alta en esta sede') }}</button>
        @endif
    </div>
@endif
