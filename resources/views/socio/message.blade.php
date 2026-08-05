<x-layouts.socio :title="$thread->subject">
    <header class="mb-4">
        <a href="{{ route('socio.messages') }}" class="text-sm font-medium text-brand dark:text-slate-100">&larr; {{ __('Mensajes') }}</a>
        <h1 class="mt-1 text-xl font-semibold leading-snug">{{ $thread->subject }}</h1>
    </header>

    <div class="space-y-3">
        @foreach ($messages as $message)
            @php $mine = $message->author === \App\Enums\MessageAuthor::MEMBER; @endphp
            <div class="flex {{ $mine ? 'justify-end' : 'justify-start' }}">
                <div class="max-w-[85%] rounded-2xl px-4 py-2.5 text-sm shadow-sm
                    {{ $mine
                        ? 'bg-brand text-white'
                        : 'border border-line bg-surface text-ink dark:border-slate-800 dark:bg-slate-900 dark:text-slate-100' }}">
                    <p class="mb-1 text-[11px] font-medium {{ $mine ? 'text-white/80' : 'text-ink-muted dark:text-slate-400' }}">
                        {{ $message->author->label() }} · {{ $message->created_at->format('d/m/Y H:i') }}
                    </p>
                    <p class="whitespace-pre-line">{{ $message->body }}</p>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Reply --}}
    <form method="POST" action="{{ route('socio.messages.reply', ['thread' => $thread->id]) }}"
          class="sticky bottom-24 mt-6 rounded-2xl border border-line bg-surface p-3 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        @csrf
        <x-socio.textarea name="body" rows="2" maxlength="4000" required placeholder="{{ __('Escribe tu respuesta…') }}">{{ old('body') }}</x-socio.textarea>
        @error('body')<p class="mt-1 text-xs text-error">{{ $message }}</p>@enderror
        <button type="submit" class="mt-2 w-full rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-dark">
            {{ __('Responder') }}
        </button>
    </form>
</x-layouts.socio>
