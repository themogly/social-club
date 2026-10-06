{{-- Prompt 361 — a photo or ID scan KEPT from a refused attempt (this token, this browser session only — KeptUploads). It
     stands in for the empty picker: «✓ Foto guardada» with a small preview and «Cambiar», which brings the picker back; a
     new file chosen there replaces the kept one, and leaving it uses the kept one. --}}
@props(['field', 'token', 'label', 'saved'])
<div data-kept-upload="{{ $field }}" x-show="! change" class="flex items-center gap-3 rounded-xl border border-success/40 bg-success/10 p-3">
    @if ($field === 'photo')
        <img src="{{ route('socio.application.kept', ['token' => $token, 'field' => $field]) }}" alt="" class="h-14 w-14 shrink-0 rounded-lg object-cover">
    @else
        <span aria-hidden="true" class="flex h-14 w-14 shrink-0 items-center justify-center rounded-lg bg-surface text-2xl dark:bg-slate-800">🪪</span>
    @endif
    <div class="min-w-0 flex-1">
        <p class="text-sm font-semibold text-success">{{ $saved }}</p>
        <p class="text-xs text-ink-muted dark:text-slate-400">{{ $label }}</p>
    </div>
    <button type="button" x-on:click="change = true" data-kept-change
            class="inline-flex min-h-11 items-center rounded-xl border border-line bg-surface px-3 text-sm font-semibold text-ink dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">{{ __('Cambiar') }}</button>
</div>
