{{-- Socios — the counter membership tab (prompt 127): find a member, see what's owed, collect a fee. Thin
     shell over the shared fee-collection concern (RecordFeePayment). Cash reconciles against the open till. --}}
<div>
    @include('livewire.counter.partials.counter-surface')

    @if (! $this->handoverActive())

    {{-- Prompt 175 — the same chain, resolved to one. Socios has no till step (an unpaid fee taken in cash is
         refused by the fee action itself, which states its own reason) and no member step: finding the socio
         IS the work. Both are absent from the chain rather than false. --}}
    @php
        $blocker = \App\Support\CounterBlocker::first([
            \App\Support\CounterBlocker::SEDE => ! $noLocation,
            \App\Support\CounterBlocker::OPERATOR => $this->hasOperator(),
        ]);
    @endphp

    @if (\App\Support\CounterBlocker::rendersInPage($blocker))
        <x-counter.blocking-state
            data-blocker="sede"
            icon="📍"
            :heading="$mustChooseLocation ? __('Elige tu sede') : __('Sin sede asignada')"
            :body="$mustChooseLocation ? __('Trabajas en varias sedes. Selecciona en la barra superior en cuál estás.') : __('No tienes ninguna sede activa. Pide a un responsable que te asigne una.')"
        />
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

        {{-- ============ Prompt 174 — Alta at the counter ============

             Inside the Socios tab, NOT a sixth destination on the counter strip: that strip took prompts
             116, 130 and 132 to fit five on a portrait tablet, and "add a new one" is the same job Socios
             already does. It creates an APPLICATION, never a member — the age gate, the duplicate search
             and the versioned consent capture all live in ApproveApplication and stay there. --}}
        @if ($this->userCan('applications.review'))
            <div class="mx-auto mb-4 max-w-xl">
                <section data-alta-panel class="rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between gap-3">
                        <h1 class="text-base font-semibold">{{ __('Alta de socio/a') }}</h1>
                        <button
                            type="button"
                            wire:click="toggleAlta"
                            data-alta-toggle
                            aria-expanded="{{ $altaOpen ? 'true' : 'false' }}"
                            class="inline-flex h-11 items-center rounded-xl border border-line px-4 text-sm font-medium text-ink-muted transition hover:bg-surface-alt dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800"
                        >{{ $altaOpen ? __('Cerrar') : __('Dar de alta') }}</button>
                    </div>

                    @if ($altaOpen)
                        @php $pending = $this->pendingAltaApplications(); @endphp

                        {{-- Reviewing one that has come back --}}
                        @if ($this->altaApplication())
                            @php $application = $this->altaApplication(); $payload = $application->payload ?? []; @endphp
                            <div data-alta-review class="mt-4 space-y-3">
                                <div class="rounded-xl bg-surface-alt p-3 text-sm dark:bg-slate-800">
                                    <p class="font-semibold">{{ trim(($payload['first_name'] ?? '').' '.($payload['last_name'] ?? '')) ?: __('Solicitud sin nombre') }}</p>
                                    <p class="text-ink-muted dark:text-slate-400">{{ $payload['email'] ?? '—' }}</p>
                                    <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">
                                        {{ __('Documento') }}: {{ $payload['document_type'] ?? '—' }} ·
                                        {{ __('Nacimiento') }}: {{ $payload['date_of_birth'] ?? '—' }}
                                    </p>
                                </div>

                                <div>
                                    <label for="alta-tier" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Cuota / tier') }}</label>
                                    <select id="alta-tier" wire:model="altaTierId" class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                                        <option value="">{{ __('Elige una cuota…') }}</option>
                                        @foreach ($this->altaTiers() as $tier)
                                            <option value="{{ $tier->id }}">{{ $tier->name }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                {{-- A duplicate is a DECISION, never a default. The matches are named so the
                                     staff member can tell "this is the same person" from "same surname". --}}
                                @if ($altaDuplicateBlocked)
                                    <div data-alta-duplicates class="rounded-xl border border-warning/40 bg-warning/10 p-3 text-sm">
                                        <p class="font-semibold text-warning">{{ __('Ya existe un socio que coincide') }}</p>
                                        <ul class="mt-1 space-y-0.5">
                                            @foreach ($this->altaDuplicateMatches() as $match)
                                                <li class="text-ink dark:text-slate-200">· {{ $match->fullName() }} ({{ $match->member_no }})</li>
                                            @endforeach
                                        </ul>
                                        <button
                                            type="button"
                                            wire:click="approveAlta(true)"
                                            data-alta-override
                                            wire:confirm="{{ __('¿Aprobar de todas formas? Quedará registrado que se creó pese a la coincidencia.') }}"
                                            class="mt-3 inline-flex h-11 items-center rounded-xl border border-warning/50 px-4 text-sm font-semibold text-warning transition hover:bg-warning/10"
                                        >{{ __('Es otra persona: dar de alta igualmente') }}</button>
                                    </div>
                                @endif

                                <div class="flex gap-2">
                                    <button type="button" wire:click="approveAlta" data-alta-approve class="h-12 flex-1 rounded-xl bg-brand text-base font-semibold text-white transition hover:bg-brand-dark">{{ __('Aprobar y dar de alta') }}</button>
                                    <button type="button" wire:click="cancelAltaReview" class="inline-flex h-12 items-center rounded-xl border border-line px-4 text-sm text-ink-muted dark:border-slate-700 dark:text-slate-400">{{ __('Cancelar') }}</button>
                                </div>
                            </div>
                        @else
                            {{-- Two ways to start the SAME record: hand the tablet over, or send a link. --}}
                            <div class="mt-4 space-y-4">
                                <button type="button" wire:click="handOverForAlta" data-alta-handover class="h-14 w-full rounded-xl bg-brand text-base font-semibold text-white transition hover:bg-brand-dark">{{ __('Entregar la tablet para que rellene sus datos') }}</button>

                                <div>
                                    <label for="alta-email" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('…o enviar una invitación por email') }}</label>
                                    <div class="mt-1 flex gap-2">
                                        <input id="alta-email" type="email" inputmode="email" wire:model="altaInviteEmail" autocomplete="off" placeholder="socio@example.es" class="h-12 min-w-0 flex-1 rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                                        <button type="button" wire:click="sendAltaInvitation" data-alta-invite class="inline-flex h-12 shrink-0 items-center rounded-xl border border-line px-4 text-sm font-semibold transition hover:bg-surface-alt dark:border-slate-700 dark:hover:bg-slate-800">{{ __('Enviar') }}</button>
                                    </div>
                                </div>

                                @if ($pending->isNotEmpty())
                                    <div>
                                        <p class="text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Solicitudes pendientes de revisar') }}</p>
                                        <ul data-alta-pending class="mt-1 divide-y divide-line overflow-hidden rounded-xl border border-line dark:divide-slate-800 dark:border-slate-800">
                                            @foreach ($pending as $application)
                                                @php $p = $application->payload ?? []; @endphp
                                                <li>
                                                    <button type="button" wire:click="reviewAltaApplication('{{ $application->id }}')" class="flex min-h-11 w-full items-center justify-between gap-3 bg-surface px-4 py-3 text-left text-sm transition hover:bg-surface-alt dark:bg-slate-900 dark:hover:bg-slate-800">
                                                        <span class="min-w-0 truncate">{{ trim(($p['first_name'] ?? '').' '.($p['last_name'] ?? '')) ?: ($application->applicant_email ?? __('Solicitud')) }}</span>
                                                        <span class="shrink-0 text-xs text-ink-muted dark:text-slate-400">{{ $application->submitted_at?->format('d/m/Y') }}</span>
                                                    </button>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            </div>
                        @endif
                    @endif
                </section>
            </div>
        @endif

        <div class="mx-auto max-w-xl">
            <section class="rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900">
                <h1 class="text-base font-semibold">{{ __('Cobro de cuota') }}</h1>

                @if ($feeMember)
                    <div class="mt-3 flex items-start justify-between gap-3 rounded-xl bg-surface-alt p-3 dark:bg-slate-800">
                        {{-- The photo is already at the counter (prompt 157) and stays. Served through the
                             authorised, access-logged endpoint — never a raw path. --}}
                        @if ($photoUrl)
                            <img src="{{ $photoUrl }}" alt="" class="h-14 w-14 shrink-0 rounded-xl object-cover">
                        @else
                            <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-brand-tint text-base font-bold text-brand dark:bg-slate-700 dark:text-slate-200">
                                {{ mb_strtoupper(mb_substr($feeMember->first_name, 0, 1).mb_substr($feeMember->last_name, 0, 1)) }}
                            </div>
                        @endif

                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold">{{ $feeMember->fullName() }}</p>
                            <p class="text-sm text-ink-muted dark:text-slate-400">
                                {{ $feeMember->member_no }}
                                <span class="rounded-full border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide">{{ $feeMember->status->label() }}</span>
                            </p>
                            @if ($membership)
                                <p class="mt-1 text-sm">
                                    {{-- Prompt 177: the TIER is named, not just its price — "what tier am I on"
                                         is one of the questions this screen exists to answer. --}}
                                    {{ __('Cuota') }}: <span class="font-medium">{{ $membership->tier?->name ?? '—' }}</span>
                                    · <span class="font-medium">{{ $this->money($membership->fee_cents->cents) }}</span>
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

                    {{-- ============ Prompt 177 — the member RECORD. Reading only. ============

                         Prompt 127 kept this screen deliberately small (collect a fee, see what is owed) and
                         that boundary stands: renewals, tier changes, suspensions and limits remain in the
                         admin panel where they carry real authorisation weight. What is added here is
                         READING. Telling a socio when their membership expires or what they collected last
                         week is the most ordinary question asked at a counter, and answering it should not
                         require leaving the counter — which is the whole point of the counter-first design.

                         Every figure below comes from the resolver that already owns it
                         (ResolveMemberLimits, ResolveMemberEligibility, Wallet). If one ever disagrees with
                         the dispensary, this screen is wrong and the resolver is right. --}}
                    <div data-member-record class="mt-3 space-y-3">
                        {{-- What they may still have — the same figures the POS puts on its cart. --}}
                        @if ($limits)
                            @php
                                $pct = $limits->monthlyPercent();
                                $gaugeBar = match ($limits->gaugeState()) { 'alert' => 'bg-error', 'warning' => 'bg-warning', default => 'bg-success' };
                                $gaugeText = match ($limits->gaugeState()) { 'alert' => 'text-error', 'warning' => 'text-warning', default => 'text-success' };
                            @endphp
                            <div data-member-allowance class="rounded-xl border border-line p-3 dark:border-slate-700">
                                <div class="flex items-baseline justify-between gap-2">
                                    <span class="text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Restante hoy') }}</span>
                                    <span class="text-base font-bold {{ $gaugeText }}">{{ $this->grams($limits->dailyRemainingCg()) }}</span>
                                </div>
                                <div class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                                    <div class="h-full rounded-full {{ $gaugeBar }}" style="width: {{ min($pct, 100) }}%"></div>
                                </div>
                                <p class="mt-1 text-[11px] text-ink-muted dark:text-slate-400">
                                    {{ __('Mes') }}: {{ $this->grams($limits->monthlyUsedCg) }} / {{ $this->grams($limits->monthlyLimitCg) }} · {{ $pct }}%
                                </p>
                            </div>
                        @endif

                        <dl class="grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm">
                            <div>
                                <dt class="text-ink-muted dark:text-slate-400">{{ __('Monedero') }}</dt>
                                <dd class="font-semibold {{ $walletCents < 0 ? 'text-error' : '' }}">{{ $this->money($walletCents) }}</dd>
                            </div>
                            <div>
                                <dt class="text-ink-muted dark:text-slate-400">{{ __('Carencia') }}</dt>
                                <dd class="font-medium">
                                    @if ($feeMember->carencia_ends_at !== null && $feeMember->carencia_ends_at->isFuture())
                                        <span class="text-warning">{{ __('Hasta') }} {{ $feeMember->carencia_ends_at->format('d/m/Y') }}</span>
                                    @else
                                        {{ __('Cumplida') }}
                                    @endif
                                </dd>
                            </div>
                        </dl>

                        {{-- Why they might be blocked — asked BEFORE being refused, which is the point. Same
                             shared resolver the door and the dispensary use, so the three tell one story. --}}
                        @if ($verdict)
                            <div data-member-verdict class="rounded-xl border p-3 text-sm {{ $verdict->isClear() ? 'border-success/30 bg-success/10' : 'border-warning/30 bg-warning/5' }}">
                                @if ($verdict->isClear())
                                    <p class="font-semibold text-success">✓ {{ __('Apto para dispensar.') }}</p>
                                @else
                                    <p class="font-semibold text-warning">{{ __('Motivos que pueden impedir dispensar') }}</p>
                                    <ul class="mt-1 space-y-1">
                                        @foreach ($verdict->rules as $rule)
                                            @continue($rule['satisfied'])
                                            @php $remedy = \App\Support\VerdictRemedy::describe($rule, $feeMember, $location); @endphp
                                            <li>
                                                <span class="{{ in_array($rule['mode'], ['BLOCK', 'OVERRIDE'], true) ? 'text-error' : 'text-warning' }}">·</span>
                                                <span class="text-ink dark:text-slate-200">{{ $remedy['detail'] ?? $rule['message'] }}</span>
                                                @if (! empty($remedy['remedy']))
                                                    <span class="block pl-3 text-xs text-ink-muted dark:text-slate-400">{{ $remedy['remedy'] }}</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endif

                        {{-- Collections. CLOSED by default: what a named person collected is Article 9 data on
                             a screen in a room with the next socio behind them, and the summary above already
                             answers the usual question. One deliberate tap, bound to this socio — change
                             socio and it closes itself. --}}
                        <div>
                            <button
                                type="button"
                                wire:click="toggleHistory"
                                data-history-toggle
                                aria-expanded="{{ $this->historyIsForCurrentMember() ? 'true' : 'false' }}"
                                class="inline-flex h-11 items-center gap-2 rounded-xl border border-line px-4 text-sm font-medium text-ink-muted transition hover:bg-surface-alt dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800"
                            >
                                {{ $this->historyIsForCurrentMember() ? __('Ocultar dispensaciones') : __('Ver últimas dispensaciones') }}
                            </button>

                            @if ($recent !== null)
                                <ul data-member-history class="mt-2 divide-y divide-line overflow-hidden rounded-xl border border-line text-sm dark:divide-slate-800 dark:border-slate-800">
                                    @forelse ($recent as $dispensation)
                                        <li class="flex items-center justify-between gap-3 px-3 py-2">
                                            <span class="min-w-0">
                                                <span class="block truncate">{{ $dispensation->lines->pluck('genetic_name_snapshot')->filter()->implode(', ') ?: __('Dispensación') }}</span>
                                                <span class="block text-xs text-ink-muted dark:text-slate-400">{{ $dispensation->created_at->format('d/m/Y H:i') }}</span>
                                            </span>
                                            <span class="shrink-0 font-medium tabular-nums">{{ $this->grams((int) $dispensation->lines->sum(fn ($line) => (int) $line->getRawOriginal('grams_cg'))) }}</span>
                                        </li>
                                    @empty
                                        <li class="px-3 py-3 text-ink-muted dark:text-slate-400">{{ __('Todavía no ha recogido nada en esta sede.') }}</li>
                                    @endforelse
                                </ul>
                            @endif
                        </div>
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
