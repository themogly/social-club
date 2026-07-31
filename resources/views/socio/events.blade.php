<x-layouts.socio :title="__('Eventos')">
    @php($goingValue = \App\Enums\EventRsvpStatus::GOING->value)

    <header class="mb-4 flex items-center justify-between">
        <h1 class="text-xl font-semibold">{{ __('Eventos') }}</h1>
        <a href="{{ route('socio.announcements') }}" class="text-sm font-medium text-brand dark:text-slate-100">{{ __('Avisos') }} &rarr;</a>
    </header>

    @if (session('status'))
        <p class="mb-3 rounded-lg bg-brand-tint p-3 text-sm text-brand dark:bg-slate-800 dark:text-slate-200">{{ session('status') }}</p>
    @endif
    @error('rsvp')
        <p class="mb-3 rounded-lg bg-warning/10 p-3 text-sm text-warning">{{ $message }}</p>
    @enderror

    @forelse ($events as $event)
        @php($mine = $myRsvps[$event->id] ?? null)
        @php($mineValue = $mine instanceof \App\Enums\EventRsvpStatus ? $mine->value : $mine)
        @php($iAmGoing = $mineValue === $goingValue)
        @php($full = $event->capacity !== null && $event->going_count >= $event->capacity && ! $iAmGoing)

        <article class="mt-3 rounded-2xl border border-line bg-surface p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-start justify-between gap-3">
                <h2 class="font-semibold leading-snug">{{ $event->title }}</h2>
                @if ($event->location)
                    <span class="shrink-0 rounded-full bg-brand-tint px-2 py-0.5 text-[11px] font-medium text-brand dark:bg-slate-800 dark:text-slate-300">{{ $event->location->name }}</span>
                @endif
            </div>

            @if ($event->starts_at)
                <p class="mt-1 text-sm font-medium text-brand dark:text-slate-200">{{ $event->starts_at->format('d/m/Y · H:i') }}</p>
            @endif
            @if ($event->description)
                <p class="mt-1.5 whitespace-pre-line text-sm text-ink-muted dark:text-slate-300">{{ $event->description }}</p>
            @endif

            <div class="mt-3 flex items-center justify-between gap-3">
                <p class="text-xs text-ink-muted dark:text-slate-400">
                    @if ($event->capacity !== null)
                        {{ __(':n de :cap confirmados', ['n' => $event->going_count, 'cap' => $event->capacity]) }}
                    @else
                        {{ __(':n confirmados', ['n' => $event->going_count]) }}
                    @endif
                </p>

                <form method="POST" action="{{ route('socio.events.rsvp', ['event' => $event->id]) }}">
                    @csrf
                    @if ($iAmGoing)
                        <x-button type="submit" size="sm">{{ __('Asistiré ✓') }}</x-button>
                    @elseif ($full)
                        <x-button variant="secondary" size="sm" disabled>{{ __('Aforo completo') }}</x-button>
                    @else
                        <x-button type="submit" variant="outline" size="sm">{{ __('Asistiré') }}</x-button>
                    @endif
                </form>
            </div>
        </article>
    @empty
        <div class="mt-6 rounded-2xl border border-dashed border-line bg-surface p-8 text-center dark:border-slate-800 dark:bg-slate-900">
            <p class="text-sm text-ink-muted dark:text-slate-400">{{ __('No hay eventos próximos.') }}</p>
        </div>
    @endforelse
</x-layouts.socio>
