{{-- A BLOCKED socio replaces the selling surface (prompt 225).

     The owner: *"If the member is blocked it should just hide the dispensary completely and say the fee is
     due — take it or waive it — and record it."*

     Until now a socio whose verdict BLOCKS got the full catalogue, a working weight pad and a basket they
     could fill, beside a warning that none of it could be committed. That is 175's own philosophy inverted —
     *a blocking state replaces the work, it does not sit beside it* — applied to every precondition except
     this one. The missing-sede state blocks the pane; the missing-till state blocks the pane; the missing
     MEMBER blocks the pane. A present-but-ineligible member did not, and it is the only one of the four the
     operator can actually resolve from here.

     **AMBER, not red** (the audit's ramp rule): blocked is a state to resolve, not a destructive act. Red is
     reserved for destructive.

     **The gate is not becoming a picture.** `commitDispensation()` refuses this member server-side exactly as
     before and says why; this changes what renders, never what the server allows. The basket is untouched —
     one built before the block appeared survives, and is committable the moment the block is resolved.

     **What is NOT here:** the Barra screen. A blocked socio can still be sold a coffee — that screen has no
     MEMBER step in its chain at all, which is its whole design. --}}
@php
    // The ACTOR's wording, and the ACTOR's action (prompt 211): a remedy must never instruct somebody to do
    // something they hold no permission for. One resolution per blocking rule, in the verdict's own order.
    $blockedRules = collect($verdict?->rules ?? [])
        ->reject(fn (array $rule): bool => (bool) ($rule['satisfied'] ?? false))
        ->filter(fn (array $rule): bool => in_array($rule['mode'] ?? '', ['BLOCK', 'OVERRIDE'], true))
        ->values();
    $blockedKeys = $blockedRules->pluck('rule')->all();
@endphp

<div data-blocked-member class="flex min-h-0 flex-1 flex-col gap-4">
    <section class="rounded-2xl border border-warning/40 bg-warning/10 p-5">
        <div class="flex items-start gap-3">
            <span aria-hidden="true" class="text-3xl leading-none">⛔</span>
            <div class="min-w-0">
                <h2 class="text-lg font-bold text-warning">{{ __('No se puede dispensar a :name', ['name' => $member->fullName()]) }}</h2>
                <p class="mt-0.5 text-sm text-ink dark:text-slate-200">
                    {{ __('Resuelve lo siguiente y el dispensario vuelve. La cesta se conserva.') }}
                </p>
            </div>
        </div>

        {{-- Every blocking reason, said once, in the member's terms. Announced once (prompt 199): this is the
             only place the block is stated on this screen — the cart's verdict list drops its copy while the
             surface is up. --}}
        <ul data-blocked-reasons role="status" aria-live="polite" class="mt-4 space-y-2">
            @foreach ($blockedRules as $rule)
                @php($remedy = \App\Support\VerdictRemedy::describe($rule, $member, $location, auth()->user()))
                <li class="rounded-xl border border-warning/30 bg-surface px-4 py-3 dark:border-slate-700 dark:bg-slate-900">
                    <p class="text-sm font-semibold">{{ $remedy['detail'] }}</p>
                    @if ($remedy['remedy'])
                        <p class="mt-0.5 text-xs text-ink-muted dark:text-slate-400">{{ $remedy['remedy'] }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    {{-- THE RESOLUTIONS. One per rule that HAS one — `EligibilityRule::hasCounterAction()` decides, so a rule
         with no counter-side fix (a sanction, an age) renders its explanation above and no control at all.
         No dead buttons: 211's rule, and the reason this iterates rather than listing two cases. --}}
    @if (in_array(\App\Enums\EligibilityRule::UNPAID_FEE->value, $blockedKeys, true))
        <section data-blocked-resolution="unpaid_fee" class="rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900">
            <h3 class="text-base font-semibold">{{ __('Cuota pendiente') }}</h3>
            <p class="mt-0.5 text-sm text-ink-muted dark:text-slate-400">{{ __('Cóbrala o condónala con un motivo. Ambas quedan registradas.') }}</p>
            {{-- The SAME partial the door and the member card use — collect (127) and 219's waiver inside it.
                 One panel, three hosts; a fourth copy is how the one that decides money would drift. --}}
            @include('livewire.counter.partials.inline-fee')
        </section>
    @endif

    @if (in_array(\App\Enums\EligibilityRule::MEMBERSHIP->value, $blockedKeys, true))
        <section data-blocked-resolution="membership" class="rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900">
            <h3 class="text-base font-semibold">{{ __('Membresía') }}</h3>
            @include('livewire.counter.partials.membership-fix')
        </section>
    @endif

    {{-- The way past this socio: the same shared lookup (194). Without it a blocked member would be a dead
         end of a different kind — the operator could neither resolve them nor serve the next person. --}}
    <section class="rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900">
        <h3 class="text-sm font-semibold text-ink-muted dark:text-slate-400">{{ __('O atiende a otro socio') }}</h3>
        <div class="mt-2">
            @include('livewire.counter.partials.member-lookup', ['autofocus' => false])
        </div>
    </section>
</div>
