@props([
    'id',
    'label',
    'accept' => null,
    'capture' => null,
    'hint' => null,
])

{{--
    A branded file field for the counter (prompt 272). The staff wizard used the browser's own file input, which
    renders Chrome's "Choose File / No file chosen" — in the BROWSER's language, inside a Spanish UI, as an
    unbranded control; the one piece of copy i18n parity cannot catch, because it is not the app's string. This is
    the counter's photo-capture pattern made reusable: a visually hidden input inside a shared-variant button label,
    and a translated file-name line. The input keeps its id, and the CALLER's `wire:model` and data-* hooks pass
    straight through to it — the caller spells `wire:model` in its own bytes, which is what the wizard's field-parity
    guard reads — so the close guard's DOM dirty check (`input[type=file].files`) and every hook see the same element.
--}}
@php($model = $attributes->wire('model')->value())
<div x-data="{ fileName: '' }">
    <p id="{{ $id }}-label" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ $label }}</p>
    <div class="mt-1 flex min-w-0 items-center gap-3">
        <x-button as="label" variant="secondary" size="sm" class="min-h-11 shrink-0">
            <input id="{{ $id }}" type="file" class="sr-only"
                   @if ($accept) accept="{{ $accept }}" @endif
                   @if ($capture) capture="{{ $capture }}" @endif
                   aria-labelledby="{{ $id }}-label {{ $id }}-choose"
                   @error($model) aria-invalid="true" aria-describedby="{{ $model }}-error" @enderror
                   x-on:change="fileName = $event.target.files[0]?.name ?? ''"
                   {{ $attributes }}>
            <span id="{{ $id }}-choose">{{ __('Elegir archivo') }}</span>
        </x-button>
        <span data-file-name class="min-w-0 truncate text-sm text-ink-muted dark:text-slate-400" x-text="fileName || @js(__('Ningún archivo'))">{{ __('Ningún archivo') }}</span>
    </div>
    @if ($hint)
        <p class="mt-0.5 text-[11px] leading-tight text-ink-muted dark:text-slate-400">{{ $hint }}</p>
    @endif
    <x-socio.field-error :name="$model" />
</div>
