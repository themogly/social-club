{{-- Prompt 91: surface the operator requirement BEFORE the work, where the eye lands — not on submit,
     after the form is filled. Rendered above each till form (cash movement, expense, fee) whenever no
     operator is identified; the form's controls are also disabled (a gated control that explains itself,
     the reverse of prompt 60's "never a silent dead control"). --}}
<div data-needs-operator role="note" class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-warning/40 bg-warning/10 px-4 py-3">
    <p class="text-sm font-medium text-warning">{{ __('Identifícate como operario para registrar movimientos, gastos o cobros de cuota.') }}</p>
    <button type="button" wire:click="openOperatorPanel" data-identify-operator class="shrink-0 rounded-lg bg-warning px-3 py-1.5 text-xs font-semibold text-white transition hover:opacity-90">
        {{ __('Identificarme') }}
    </button>
</div>
