<x-filament-panels::page>
    {{-- Prompt 353 — one guide, opened from the Manual (the way back). --}}
    <div>
        <a href="{{ \App\Filament\Pages\Manual::getUrl() }}" data-way-back class="mb-4 inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-primary-600 hover:underline dark:text-primary-400">← {{ __('Manual') }}</a>
        @include('guides.partials.article', ['page' => $page, 'heading' => false])
    </div>
</x-filament-panels::page>
