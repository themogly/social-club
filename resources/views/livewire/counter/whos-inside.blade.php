{{-- Live occupancy. Polls so elapsed times + the aforo ring stay current, and also
     re-renders on the `checkins-updated` event. Never cached — always a fresh query. --}}
<div wire:poll.15s class="flex flex-col gap-4 rounded-2xl border border-line bg-surface p-4 dark:border-slate-800 dark:bg-slate-900 sm:p-5">
    @php
        $radius = 40;
        $circ = 2 * M_PI * $radius;
        $frac = $fraction !== null ? min($fraction, 1.0) : 0.0;
        $offset = $circ * (1 - $frac);
        $ringColour = match ($state) {
            'at' => 'text-error',
            'near' => 'text-warning',
            'ok' => 'text-success',
            default => 'text-brand',
        };
    @endphp

    {{-- Aforo ring: colour + the current/capacity number (never colour alone). --}}
    <div class="flex items-center gap-4">
        <div class="relative inline-flex shrink-0 items-center justify-center">
            <svg viewBox="0 0 100 100" class="h-28 w-28 -rotate-90">
                <circle cx="50" cy="50" r="{{ $radius }}" fill="none" stroke-width="10" class="stroke-slate-200 dark:stroke-slate-700" />
                <circle
                    cx="50" cy="50" r="{{ $radius }}"
                    fill="none" stroke="currentColor" stroke-width="10" stroke-linecap="round"
                    class="{{ $ringColour }} transition-all"
                    stroke-dasharray="{{ $circ }}"
                    stroke-dashoffset="{{ $offset }}"
                />
            </svg>
            <div class="absolute inset-0 flex flex-col items-center justify-center leading-none">
                <span class="text-3xl font-bold {{ $ringColour }}">{{ $current }}</span>
                <span class="mt-1 text-xs text-ink-muted dark:text-slate-400">
                    {{ $capacity !== null ? '/ '.$capacity : __('sin aforo') }}
                </span>
            </div>
        </div>

        <div class="min-w-0">
            <h2 class="text-lg font-semibold">{{ __('Aforo') }}</h2>
            <p class="text-sm text-ink-muted dark:text-slate-400">
                @if ($state === 'at')
                    <span class="font-medium text-error">{{ __('Completo') }}</span>
                @elseif ($state === 'near')
                    <span class="font-medium text-warning">{{ __('Casi completo') }}</span>
                @elseif ($state === 'none')
                    {{ __('Sin límite de aforo') }}
                @else
                    <span class="font-medium text-success">{{ __('Disponible') }}</span>
                @endif
            </p>
            @if ($capacity !== null && $fraction !== null)
                <p class="mt-0.5 text-xs text-ink-muted dark:text-slate-400">{{ (int) round($fraction * 100) }}%</p>
            @endif
        </div>
    </div>

    {{-- Who's inside --}}
    <div class="flex items-center justify-between border-t border-line pt-3 dark:border-slate-800">
        <h3 class="text-sm font-semibold text-ink-muted dark:text-slate-400">{{ __('Dentro ahora') }} ({{ $checkIns->count() }})</h3>
        @if ($checkIns->isNotEmpty())
            <button
                type="button"
                wire:click="checkOutAll"
                wire:confirm="{{ __('¿Registrar la salida de todos los socios?') }}"
                class="rounded-lg px-3 py-1.5 text-sm font-medium text-ink-muted transition hover:bg-black/5 dark:text-slate-400 dark:hover:bg-white/5"
            >
                {{ __('Salida de todos') }}
            </button>
        @endif
    </div>

    <ul class="-mx-1 divide-y divide-line dark:divide-slate-800">
        @forelse ($checkIns as $checkIn)
            <li wire:key="ci-{{ $checkIn->id }}" class="flex items-center gap-3 px-1 py-2.5">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-tint text-sm font-bold text-brand dark:bg-slate-800 dark:text-slate-200">
                    {{ mb_strtoupper(mb_substr($checkIn->member?->first_name ?? '', 0, 1).mb_substr($checkIn->member?->last_name ?? '', 0, 1)) }}
                </div>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium">{{ $checkIn->member?->fullName() ?? __('Socio') }}</p>
                    <p class="text-xs text-ink-muted dark:text-slate-400">
                        {{ $checkIn->checked_in_at->format('H:i') }} · {{ $checkIn->checked_in_at->shortAbsoluteDiffForHumans() }}
                    </p>
                </div>
                <button
                    type="button"
                    wire:click="checkOut('{{ $checkIn->id }}')"
                    class="shrink-0 rounded-lg border border-line px-3 py-2 text-sm font-medium text-ink-muted transition hover:bg-surface-alt dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                >
                    {{ __('Salida') }}
                </button>
            </li>
        @empty
            <li class="px-1 py-6 text-center text-sm text-ink-muted dark:text-slate-400">{{ __('No hay nadie dentro.') }}</li>
        @endforelse
    </ul>
</div>
