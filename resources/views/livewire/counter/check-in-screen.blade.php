<div class="flex flex-col gap-5 lg:grid lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
    {{-- ================= LEFT: the door workflow ================= --}}
    <div class="flex flex-col gap-5">
        @if ($noLocation)
            {{-- Intentional empty state: an operator with no assigned sede. Still a 200. --}}
            <div class="rounded-2xl border border-line bg-surface p-8 text-center dark:border-slate-800 dark:bg-slate-900">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-surface-alt text-2xl dark:bg-slate-800">📍</div>
                <h2 class="mt-4 text-lg font-semibold">{{ __('Sin sede asignada') }}</h2>
                <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">
                    {{ __('No tienes ninguna sede activa. Pide a un responsable que te asigne una para usar la recepción.') }}
                </p>
            </div>
        @else
            {{-- Flash --}}
            @if ($flashMessage)
                <div
                    wire:key="flash"
                    @class([
                        'flex items-center justify-between gap-3 rounded-xl border px-4 py-3 text-sm font-medium',
                        'border-success/30 bg-success/10 text-success' => $flashType === 'success',
                        'border-warning/30 bg-warning/10 text-warning' => $flashType === 'warning',
                        'border-error/30 bg-error/10 text-error' => $flashType === 'error',
                    ])
                >
                    <span>{{ $flashMessage }}</span>
                    <button type="button" wire:click="$set('flashMessage', null)" class="shrink-0 rounded-md px-2 py-1 opacity-70 hover:opacity-100">✕</button>
                </div>
            @endif

            {{-- Scan + search --}}
            <section class="rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900 sm:p-5">
                <form wire:submit="submitScan">
                    <label for="scan" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Escanear tarjeta de socio') }}</label>
                    <div class="mt-2 flex gap-2">
                        <input
                            id="scan"
                            type="text"
                            wire:model="scan"
                            autofocus
                            autocomplete="off"
                            spellcheck="false"
                            placeholder="{{ __('Escanea la tarjeta o escribe el código y pulsa Enter') }}"
                            class="h-14 min-w-0 flex-1 rounded-xl border border-line bg-surface px-4 text-lg text-ink placeholder:text-ink-muted/60 focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        >
                        <button type="submit" class="h-14 shrink-0 rounded-xl bg-brand px-6 text-base font-semibold text-white transition hover:bg-brand-dark focus:outline-none focus:ring-2 focus:ring-brand/40">
                            {{ __('Buscar') }}
                        </button>
                    </div>
                </form>

                <div class="mt-4">
                    <label for="search" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('o busca por nombre / nº de socio') }}</label>
                    <input
                        id="search"
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        autocomplete="off"
                        placeholder="{{ __('Ej. García o M-00042') }}"
                        class="mt-2 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base text-ink placeholder:text-ink-muted/60 focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                    >

                    @if (mb_strlen(trim($search)) >= 2)
                        <ul class="mt-2 divide-y divide-line overflow-hidden rounded-xl border border-line dark:divide-slate-800 dark:border-slate-800">
                            @forelse ($searchResults as $result)
                                <li>
                                    <button
                                        type="button"
                                        wire:click="selectMember('{{ $result->id }}')"
                                        class="flex w-full items-center justify-between gap-3 bg-surface px-4 py-3 text-left transition hover:bg-surface-alt dark:bg-slate-900 dark:hover:bg-slate-800"
                                    >
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
            </section>

            {{-- Member card OR prompt --}}
            @if ($member)
                @php
                    $inCarencia = $member->carencia_ends_at !== null && $member->carencia_ends_at->isFuture();
                    $statusColour = match ($member->status) {
                        \App\Enums\MemberStatus::ACTIVE => 'border-success/30 bg-success/10 text-success',
                        \App\Enums\MemberStatus::APPLICANT => 'border-warning/30 bg-warning/10 text-warning',
                        \App\Enums\MemberStatus::SUSPENDED, \App\Enums\MemberStatus::EXPELLED => 'border-error/30 bg-error/10 text-error',
                        default => 'border-line bg-surface-alt text-ink-muted dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300',
                    };
                @endphp

                <section class="rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900 sm:p-6">
                    <div class="flex items-start gap-4">
                        {{-- Photo / initials --}}
                        @if ($photoUrl)
                            <img src="{{ $photoUrl }}" alt="" class="h-24 w-24 shrink-0 rounded-2xl object-cover sm:h-28 sm:w-28">
                        @else
                            <div class="flex h-24 w-24 shrink-0 items-center justify-center rounded-2xl bg-brand-tint text-3xl font-bold text-brand dark:bg-slate-800 dark:text-slate-200 sm:h-28 sm:w-28">
                                {{ mb_strtoupper(mb_substr($member->first_name, 0, 1).mb_substr($member->last_name, 0, 1)) }}
                            </div>
                        @endif

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="truncate text-2xl font-bold">{{ $member->fullName() }}</h2>
                                <span class="rounded-full border px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide {{ $statusColour }}">{{ __($member->status->value) }}</span>
                            </div>
                            <p class="mt-0.5 text-sm text-ink-muted dark:text-slate-400">{{ $member->member_no }}</p>

                            <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm">
                                <div>
                                    <dt class="text-ink-muted dark:text-slate-400">{{ __('Cuota / tier') }}</dt>
                                    <dd class="font-medium">{{ $membership?->tier?->name ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-ink-muted dark:text-slate-400">{{ __('Vence') }}</dt>
                                    <dd class="font-medium">{{ $membership?->expires_at?->format('d/m/Y') ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-ink-muted dark:text-slate-400">{{ __('Monedero') }}</dt>
                                    <dd class="font-semibold {{ $walletCents < 0 ? 'text-error' : '' }}">{{ $this->money($walletCents) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-ink-muted dark:text-slate-400">{{ __('Carencia') }}</dt>
                                    <dd class="font-medium">
                                        @if ($inCarencia)
                                            <span class="text-warning">{{ __('Hasta') }} {{ $member->carencia_ends_at->format('d/m/Y') }}</span>
                                        @else
                                            {{ __('Cumplida') }}
                                        @endif
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <button type="button" wire:click="clearMember" class="shrink-0 rounded-lg px-3 py-2 text-sm text-ink-muted transition hover:bg-black/5 dark:text-slate-400 dark:hover:bg-white/5">
                            {{ __('Cerrar') }}
                        </button>
                    </div>

                    {{-- Consumption gauge (MTD grams / monthly limit) — colour + numbers, never colour alone --}}
                    @if ($limits)
                        @php
                            $pct = $limits->monthlyPercent();
                            $gaugeState = $limits->gaugeState();
                            $gaugeBar = match ($gaugeState) { 'alert' => 'bg-error', 'warning' => 'bg-warning', default => 'bg-success' };
                            $gaugeText = match ($gaugeState) { 'alert' => 'text-error', 'warning' => 'text-warning', default => 'text-success' };
                        @endphp
                        <div class="mt-5">
                            <div class="flex items-baseline justify-between text-sm">
                                <span class="font-medium">{{ __('Consumo del mes') }}</span>
                                <span class="font-semibold {{ $gaugeText }}">{{ $this->grams($limits->monthlyUsedCg) }} / {{ $this->grams($limits->monthlyLimitCg) }} · {{ $pct }}%</span>
                            </div>
                            <div class="mt-1.5 h-3 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                                <div class="h-full rounded-full {{ $gaugeBar }}" style="width: {{ min($pct, 100) }}%"></div>
                            </div>
                            <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">{{ __('Hoy') }}: {{ $this->grams($limits->dailyUsedCg) }} / {{ $this->grams($limits->dailyLimitCg) }}</p>
                        </div>
                    @endif

                    {{-- Active sanction --}}
                    @if ($sanction)
                        <div class="mt-4 rounded-xl border border-error/30 bg-error/10 px-4 py-3 text-sm text-error">
                            <p class="font-semibold">{{ __('Sanción activa') }} · {{ __($sanction->type->value) }}</p>
                            @if ($sanction->reason)
                                <p class="mt-0.5">{{ $sanction->reason }}</p>
                            @endif
                            @if ($sanction->until_date)
                                <p class="mt-0.5 opacity-80">{{ __('Hasta') }} {{ $sanction->until_date->format('d/m/Y') }}</p>
                            @endif
                        </div>
                    @endif

                    {{-- Door verdict --}}
                    @if ($verdict)
                        <div class="mt-5 border-t border-line pt-4 dark:border-slate-800">
                            @if ($verdict->isClear())
                                <div class="flex items-center gap-2 rounded-xl border border-success/30 bg-success/10 px-4 py-3 text-sm font-semibold text-success">
                                    <span>✓</span><span>{{ __('Sin incidencias. Listo para entrar.') }}</span>
                                </div>
                            @else
                                <div class="space-y-2">
                                    @foreach ($verdict->rules as $rule)
                                        @continue($rule['satisfied'])
                                        @php $isBlock = in_array($rule['mode'], ['BLOCK', 'OVERRIDE'], true); @endphp
                                        <div @class([
                                            'flex items-center justify-between gap-3 rounded-xl border px-4 py-2.5 text-sm',
                                            'border-error/30 bg-error/10 text-error' => $isBlock,
                                            'border-warning/30 bg-warning/10 text-warning' => ! $isBlock,
                                        ])>
                                            <span>{{ $rule['message'] }}</span>
                                            <span class="shrink-0 rounded-full border border-current px-2 py-0.5 text-xs font-semibold uppercase">{{ $isBlock ? __('Bloquea') : __('Aviso') }}</span>
                                        </div>
                                    @endforeach
                                    <p class="text-sm text-ink-muted dark:text-slate-400">
                                        {{ $verdict->isBlocked()
                                            ? __('Un responsable con permiso debe autorizar la entrada para continuar.')
                                            : __('Puede entrar; el aviso queda registrado.') }}
                                    </p>
                                </div>
                            @endif
                        </div>
                    @endif

                    {{-- Actions --}}
                    <div class="mt-5">
                        @if ($openCheckIn)
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <p class="text-sm text-ink-muted dark:text-slate-400">
                                    {{ __('Dentro desde') }} <span class="font-semibold text-ink dark:text-slate-100">{{ $openCheckIn->checked_in_at->format('H:i') }}</span>
                                </p>
                                <button
                                    wire:click="checkOut"
                                    wire:loading.attr="disabled"
                                    class="h-14 rounded-xl border border-line bg-surface-alt px-6 text-base font-semibold text-ink transition hover:bg-slate-200 disabled:opacity-60 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700"
                                >
                                    {{ __('Registrar salida') }}
                                </button>
                            </div>
                        @else
                            <button
                                wire:click="checkIn"
                                wire:loading.attr="disabled"
                                class="h-16 w-full rounded-xl bg-brand text-lg font-bold text-white transition hover:bg-brand-dark focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:opacity-60"
                            >
                                {{ __('Registrar entrada') }}
                            </button>
                        @endif
                    </div>

                    {{-- Manager override affordance (appears only after a blocked attempt) --}}
                    @if ($blocked)
                        <div class="mt-4 rounded-xl border border-error/30 bg-error/5 p-4">
                            <p class="text-sm font-semibold text-error">{{ __('Entrada bloqueada') }}</p>
                            <ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-error/90">
                                @foreach ($blockedReasons as $reason)
                                    <li>{{ $reason }}</li>
                                @endforeach
                            </ul>

                            @if ($canOverride)
                                <textarea
                                    wire:model="overrideReason"
                                    rows="2"
                                    placeholder="{{ __('Motivo de la excepción (queda registrado)') }}"
                                    class="mt-3 w-full rounded-xl border border-line bg-surface px-3 py-2 text-sm focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 dark:border-slate-700 dark:bg-slate-950"
                                ></textarea>
                                <button
                                    wire:click="confirmOverride"
                                    wire:loading.attr="disabled"
                                    class="mt-2 h-12 w-full rounded-xl bg-error px-6 text-base font-semibold text-white transition hover:opacity-90 disabled:opacity-60"
                                >
                                    {{ __('Autorizar y registrar entrada') }}
                                </button>
                            @else
                                <p class="mt-3 text-sm text-ink-muted dark:text-slate-400">
                                    {{ __('Un responsable con permiso (checkin.override) debe autorizar esta excepción.') }}
                                </p>
                            @endif
                        </div>
                    @endif
                </section>
            @else
                {{-- Intentional empty state before a member is held --}}
                <div class="rounded-2xl border border-dashed border-line bg-surface p-10 text-center dark:border-slate-700 dark:bg-slate-900">
                    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-surface-alt text-2xl dark:bg-slate-800">🪪</div>
                    <p class="mt-4 font-medium">{{ __('Escanea una tarjeta o busca un socio') }}</p>
                    <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">{{ __('Sus datos y el veredicto de acceso aparecerán aquí.') }}</p>
                </div>
            @endif
        @endif
    </div>

    {{-- ================= RIGHT: who's inside ================= --}}
    @unless ($noLocation)
        <livewire:counter.whos-inside />
    @endunless
</div>
