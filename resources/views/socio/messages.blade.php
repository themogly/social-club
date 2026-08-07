<x-layouts.socio :title="__('Mensajes')">
    <header class="mb-4">
        <h1 class="text-xl font-semibold">{{ __('Mensajes') }}</h1>
        <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">{{ __('Escribe al club. No es un canal de pedidos.') }}</p>
    </header>

    {{-- Start a new thread --}}
    <form method="POST" action="{{ route('socio.messages.store') }}" class="rounded-2xl border border-line bg-surface p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        @csrf
        <label for="subject" class="block text-sm font-medium">{{ __('Asunto') }}</label>
        <x-socio.input type="text" id="subject" name="subject" value="{{ old('subject') }}" maxlength="150" required class="mt-1" />
        @error('subject')<p class="mt-1 text-xs text-error">{{ $message }}</p>@enderror

        <label for="body" class="mt-3 block text-sm font-medium">{{ __('Mensaje') }}</label>
        <x-socio.textarea id="body" name="body" rows="3" maxlength="4000" required class="mt-1">{{ old('body') }}</x-socio.textarea>
        @error('body')<p class="mt-1 text-xs text-error">{{ $message }}</p>@enderror

        <x-button type="submit" size="sm" class="mt-3 w-full">{{ __('Enviar mensaje') }}</x-button>
    </form>

    {{-- Existing threads --}}
    <h2 class="mb-2 mt-6 text-sm font-semibold text-ink-muted dark:text-slate-400">{{ __('Conversaciones') }}</h2>
    @forelse ($threads as $thread)
        <a href="{{ route('socio.messages.show', ['thread' => $thread->id]) }}"
           class="mt-3 block rounded-2xl border border-line bg-surface p-4 shadow-sm hover:border-brand dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-start justify-between gap-3">
                <h3 class="font-semibold leading-snug">{{ $thread->subject }}</h3>
                <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium
                    {{ $thread->status === \App\Enums\MessageThreadStatus::OPEN ? 'bg-warning/10 text-warning' : 'bg-slate-100 text-ink-muted dark:bg-slate-800 dark:text-slate-400' }}">
                    {{ $thread->status->label() }}
                </span>
            </div>
            <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">{{ optional($thread->last_message_at)->format('d/m/Y H:i') }}</p>
        </a>
    @empty
        <div class="mt-3 rounded-2xl border border-dashed border-line bg-surface p-8 text-center dark:border-slate-800 dark:bg-slate-900">
            <p class="text-sm text-ink-muted dark:text-slate-400">{{ __('Todavía no has escrito al club.') }}</p>
        </div>
    @endforelse
</x-layouts.socio>
