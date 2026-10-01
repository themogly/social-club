{{-- Prompt 346 — the staff form never overwrites what the operator typed: where the document reads differently, it is
     offered here instead. Expects: $field. --}}
@if (isset($altaMrzOffered[$field]))
    @php($offered = $altaMrzOffered[$field])
    <p data-mrz-offer="{{ $field }}" class="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-ink-muted dark:text-slate-400">
        <span>{{ __('En el documento:') }}
            <strong class="font-semibold text-ink dark:text-slate-100">{{ $field === 'date_of_birth' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $offered) ? \Illuminate\Support\Carbon::parse($offered)->format('d/m/Y') : ($field === 'document_type' ? (\App\Enums\IdDocumentType::tryFrom($offered)?->label() ?? $offered) : $offered) }}</strong></span>
        <span aria-hidden="true">·</span>
        <button type="button" wire:click="useMrzValue('{{ $field }}')" data-mrz-use="{{ $field }}" class="inline-flex min-h-11 items-center font-semibold text-brand underline-offset-2 hover:underline dark:text-slate-100">{{ __('Usar') }}</button>
    </p>
@endif
