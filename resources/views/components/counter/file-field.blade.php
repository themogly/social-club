@props([
    'id',
    'label',
    'accept' => null,
    'camera' => null,
    'hint' => null,
])

{{--
    A branded file field for the counter (prompt 272). The staff wizard used the browser's own file input, which
    renders Chrome's "Choose File / No file chosen" — in the BROWSER's language, inside a Spanish UI, as an
    unbranded control; the one piece of copy i18n parity cannot catch, because it is not the app's string. This is
    the counter's photo-capture pattern made reusable: a visually hidden input inside a shared-variant button label,
    and a translated file-name line. The input keeps its id, and the CALLER's `wire:model`, `name` and data-* hooks pass
    straight through to it — the caller spells `wire:model` in its own bytes, which is what the wizard's field-parity
    guard reads — so the close guard's DOM dirty check (`input[type=file].files`) and every hook see the same element.

    Prompt 295 (Shane) — a photo or a scan offers TWO buttons, side by side. *Elegir archivo* is that input, with NO
    `capture`, so it opens the files and the gallery (it used to force the camera: "says browse but opens the
    camera"). *Hacer foto* is a second input with `capture` = the `camera` prop — `user` (front) for a face,
    `environment` (back) for a document — which has no name and hands its file to the field's own input
    (`resources/js/photo-buttons.js`), so there is still one field, one validation and one upload. A device with no
    camera hides *Hacer foto*.
--}}
@php($model = $attributes->wire('model')->value() ?: $attributes->get('name'))
<div x-data="{ fileName: '' }">
    <p id="{{ $id }}-label" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ $label }}</p>
    <div class="mt-1 flex min-w-0 flex-wrap items-center gap-2">
        @if ($camera)
            <x-button as="label" variant="secondary" size="sm" class="min-h-11 shrink-0" data-camera-button>
                <input type="file" class="sr-only" capture="{{ $camera }}" data-camera-for="{{ $id }}"
                       @if ($accept) accept="{{ $accept }}" @endif
                       aria-labelledby="{{ $id }}-label {{ $id }}-camera">
                <span id="{{ $id }}-camera">{{ __('Hacer foto') }}</span>
            </x-button>
        @endif
        <x-button as="label" variant="secondary" size="sm" class="min-h-11 shrink-0">
            <input id="{{ $id }}" type="file" class="sr-only"
                   @if ($accept) accept="{{ $accept }}" @endif
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
