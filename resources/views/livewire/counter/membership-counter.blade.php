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
        @include('livewire.counter.partials.counter-flash', ['anchor' => 'data-commit-feedback'])

        @unless ($openTill)
            <div class="mb-4 rounded-xl border border-warning/40 bg-warning/10 px-4 py-3 text-sm text-warning">
                {{ __('No hay caja abierta en esta sede: solo puedes cobrar cuotas con monedero hasta que se abra una.') }}
            </div>
        @endunless

        {{-- ============ Prompt 207 — the alert that sent you here, with its subjects ============

             The hub's *Requiere atención* rail links to this screen, and until 207 that was all it did: an
             alert saying *"1 membresía vence pronto"* landed the operator on an empty search box with no way
             to find out WHICH membership without already knowing the answer. It said something was wrong and
             then handed over a haystack.

             Printing the names in the rail instead was the obvious fix and the wrong one — 177 put the
             consumption list behind a deliberate tap and bound it to one socio precisely because the hub is a
             screen in a room with the next socio standing behind the current one. So the count stays there
             and the names appear HERE, where member data already belongs and where the operator is about to
             act. Each row is the ordinary `selectMember()` the search box calls: same sede scope, same
             verdict, same blocking chain. No second search box (194) — this is a list of subjects, not a
             second way to find one.

             The cap is 177's figure and is stated rather than silent: a truncated list that looks complete is
             worse than a long one.

             The BLOCK form below, not `@php(...)`: Blade lifts raw PHP out with a non-greedy
             `/(?<!@)@php(.*?)@endphp/s` BEFORE it compiles directives, so a shorthand `@php(...)` in a file
             that uses the block form later pairs with THAT file's next `@endphp` and swallows everything
             between — here it ate this whole panel and left an unbalanced `@if`. --}}
        @php
            $worklist = $this->worklist();
        @endphp

        {{-- ============ THE SCREEN'S THREE JOBS, AND THE ONE THAT OWNS IT (prompts 210 → 221) ============

             210 gave this screen two columns because it carried three jobs — signing somebody up, collecting
             a fee, reading a record — stacked in one `max-w-xl` column with the record below the fold. That
             was the right fix for the screen as it was.

             221 removes the reason for it. The owner: the sign-up *"is too small and still has the collect on
             the side… make it more user friendly."* Both halves are the same move — the sign-up becomes a
             modal (`alta-modal`) and fee collection gets the screen back as one centred column. Nothing about
             either job changed; only which surface each one owns. --}}
        <div class="mx-auto w-full max-w-[760px]">

        {{-- The screen's own heading and its one entrance. This button is the ONLY way into a sign-up now, so
             it is the page's single primary action — gated exactly as the panel it replaces was.

             An `<h2>`, not the `<h1>` the design draws: 130's rule is that the shared counter header renders
             the one `<h1>` (the screen's name in the top bar), so everything below starts at h2. The design's
             hierarchy is reproduced by SIZE, which is what it was expressing; the document outline is not the
             place to express it twice. --}}
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 id="fee-collection-heading" class="text-2xl font-bold">{{ __('Cobro de cuota') }}</h2>
                <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">{{ __('Escanea una tarjeta o busca a un socio/a por nombre o número.') }}</p>
            </div>
            @if ($this->userCan('applications.review'))
                {{-- `data-alta-toggle=""`, not the bare attribute: a component attribute BAG renders a bare name as
                     `name="name"`, which reads as two occurrences to anything counting the hook. --}}
                <x-button wire:click="toggleAlta" data-alta-toggle="" size="md" class="shrink-0 shadow-lg shadow-brand/30">
                    <span aria-hidden="true" class="mr-2 text-lg leading-none">+</span>{{ __('Nuevo socio/a') }}
                </x-button>
            @endif
        </div>


        <div data-member-column class="mt-5 min-w-0">
        @if ($worklist !== null)
            <div class="mb-4">
                <section data-alert-worklist="{{ $alert }}" class="rounded-2xl border border-brand/30 bg-brand-tint p-4 dark:border-slate-700 dark:bg-slate-800">
                    <h2 class="text-base font-semibold">{{ $worklist['title'] }}</h2>
                    <ul class="mt-3 divide-y divide-line/70 overflow-hidden rounded-xl border border-line bg-surface dark:divide-slate-800 dark:border-slate-700 dark:bg-slate-900">
                        @foreach ($worklist['rows'] as $row)
                            <li>
                                <button
                                    type="button"
                                    wire:click="selectMember('{{ $row['member_id'] }}')"
                                    data-worklist-member="{{ $row['member_id'] }}"
                                    class="flex min-h-11 w-full items-center justify-between gap-3 px-3 py-2.5 text-left transition hover:bg-brand-tint dark:hover:bg-slate-800"
                                >
                                    <span class="min-w-0 truncate text-sm font-semibold">{{ $row['name'] }}</span>
                                    <span class="shrink-0 text-xs text-ink-muted dark:text-slate-400">{{ $row['detail'] }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                    @if ($worklist['total'] > $worklist['shown'])
                        <p data-worklist-truncated class="mt-2 text-xs text-ink-muted dark:text-slate-400">
                            {{ __('Mostrando :shown de :total. Busca por nombre para el resto.', ['shown' => $worklist['shown'], 'total' => $worklist['total']]) }}
                        </p>
                    @endif
                </section>
            </div>
        @endif
            {{-- Named by the visible heading above rather than repeating it: one heading, one region. --}}
            <section aria-labelledby="fee-collection-heading" class="rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900">

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

                            {{-- Prompt 203 — who this is, which the record did not say.

                                 AGE, not date of birth, and the distinction is the decision: age answers the
                                 question a person at a counter actually has — does this match the card, are
                                 they plainly of age — while `12/04/1992` is an identifier used for identity
                                 verification everywhere else, printed on a tablet with the next socio behind
                                 them. Same reasoning 177 applied to consumption history: the summary that
                                 answers the question is on screen; the identifying detail is not here at all.

                                 Joined is an ordinary register fact and answers the seniority/carencia
                                 question the counter is asked next. --}}
                            <p data-member-identity class="mt-1 text-sm text-ink-muted dark:text-slate-400">
                                @if ($feeMember->date_of_birth)
                                    <span data-member-age>{{ __(':years años', ['years' => $feeMember->date_of_birth->age]) }}</span>
                                @else
                                    <span data-member-age>{{ __('Edad sin registrar') }}</span>
                                @endif
                                @if ($feeMember->joined_at)
                                    · {{ __('Socio desde') }} {{ $feeMember->joined_at->format('m/Y') }}
                                @endif
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
                                {{-- The register fact that tells the three cases apart. Without it an operator
                                     sees "no membership here" on somebody plainly active and cannot tell
                                     which situation they are looking at (prompt 203). --}}
                                @if ($elsewhere->isNotEmpty())
                                    <p data-membership-elsewhere class="mt-0.5 text-sm">
                                        {{ __('Activo en') }}:
                                        <span class="font-medium">{{ $elsewhere->map(fn ($m) => $m->location?->name)->filter()->join(' · ') }}</span>
                                    </p>
                                @endif
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
                    {{-- ============ Prompt 203 — the way out of the dead end ============

                         An ACTIVE member could stand here with the screen reading "Sin membresía activa en
                         esta sede" and the verdict below saying "renueva su cuota desde su ficha" — and
                         nothing on the screen did that. The three Actions that would were surfaced only in
                         the admin panel, which STAFF cannot act in, so the remedy text pointed a staff user
                         at a door they cannot open.

                         One control per case, and the wording says what will actually happen:
                           · lapsed here  → RenewMembership on the SAME row (its fee history survives)
                           · none here    → EnrolMembership on the chosen tier, at that tier's DEFAULT fee
                           · active elsewhere → the same enrolment, worded so it is clear the other sede is
                             untouched. Moving a membership changes another sede's register and stock ceiling
                             and stays in the panel behind `members.transfer`.

                         No fee box anywhere: `membership.fee.override` has not moved. --}}
                    @include('livewire.counter.partials.membership-fix')

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
                                            {{-- The actor, for the WORDING (prompt 211). No action button here:
                                                 this IS the screen the action leads to, and 203's three-case
                                                 membership panel is a few pixels below — a link back to the page
                                                 you are on is worse than no link. --}}
                                            @php $remedy = \App\Support\VerdictRemedy::describe($rule, $feeMember, $location, auth()->user()); @endphp
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
                                <p class="mt-1 text-[11px] text-ink-muted dark:text-slate-400">{{ __('Puedes cobrar el total o una parte.') }}</p>
                            </div>
                            <div>
                                <span class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Método') }}</span>
                                <div class="mt-1 grid grid-cols-2 gap-2">
                                    <button type="button" wire:click="$set('feeMethod', 'CASH')" @class(['h-11 rounded-xl border text-sm font-semibold', 'border-brand bg-brand text-white' => $feeMethod === 'CASH', 'border-line text-ink dark:border-slate-700 dark:text-slate-100' => $feeMethod !== 'CASH'])>{{ __('Efectivo') }}</button>
                                    <button type="button" wire:click="$set('feeMethod', 'WALLET')" @class(['h-11 rounded-xl border text-sm font-semibold', 'border-brand bg-brand text-white' => $feeMethod === 'WALLET', 'border-line text-ink dark:border-slate-700 dark:text-slate-100' => $feeMethod !== 'WALLET'])>{{ __('Monedero') }}</button>
                                </div>
                            </div>
                            <x-button type="submit" size="md" class="w-full" wire:loading.attr="disabled" wire:target="collectFee">{{ __('Cobrar cuota') }}</x-button>
                        </form>

                        {{-- Prompt 219 — WITH the collect control and secondary to it, never a fourth copy of
                             the form. One partial, three hosts. --}}
                        @include('livewire.counter.partials.fee-waiver')
                    @endif
                @else
                    {{-- Prompt 194 — the SAME lookup as the door, the dispensary, the till and the bar. This
                         tab's own name box could not resolve a scanned card at all. Identifying somebody is
                         the whole purpose of this screen's empty state, so it takes the cursor. --}}
                    <div>
                        @include('livewire.counter.partials.member-lookup', ['autofocus' => true, 'large' => true])
                    </div>
                @endif
            </section>

            {{-- Intentional empty state (design audit): with nobody on screen this page was two small cards
                 and ~700px of blank background at 1440x900, while the door already answers the same question
                 with the same panel. An operator who has never opened this tab now knows what is about to
                 appear in the space. Hidden the moment a socio is held — the space is theirs then. --}}
            @unless ($feeMember)
                <div class="mt-4 rounded-2xl border border-dashed border-line bg-surface p-10 text-center dark:border-slate-700 dark:bg-slate-900">
                    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-surface-alt text-2xl dark:bg-slate-800">🪪</div>
                    <p class="mt-4 font-medium">{{ __('Escanea una tarjeta o busca un socio') }}</p>
                    <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">{{ __('Su cuota, su tarifa y lo que lleva este mes aparecerán aquí.') }}</p>
                </div>
            @endunless
        </div>{{-- /member column --}}
        </div>{{-- /the centred column --}}

        {{-- The sign-up, on its own surface. Rendered only when open, and only for somebody who may review
             applications — the same gate the inline panel carried. --}}
        @if ($altaOpen && $this->userCan('applications.review'))
            @include('livewire.counter.partials.alta-modal')
        @endif

        {{-- Nothing scrolls behind an open modal. `x-effect` rather than `x-init`, so it reacts BOTH ways: an
             `x-init` inside the modal would add the class on open and have nothing left to remove it. --}}
        <div x-data x-effect="document.body.classList.toggle('overflow-hidden', $wire.altaOpen)"></div>
    @endif
@endif
</div>
