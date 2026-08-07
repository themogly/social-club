{{-- The staff-typed sign-up form (prompt 210) — the route the counter did not have.

     One writer, and that is the whole argument: these fields are validated by
     `SubmitApplicationRequest::factRules()` — literally the public form's rules — and the record is written by
     `SubmitApplication`, the same Action the public POST calls. The age gate, the duplicate search and the
     versioned consent capture all still run in `ApproveApplication` afterwards. 174's argument was that the
     audited route is the open one; this is that route with a different keyboard in front of it.

     177's boundary holds on this screen: no document SCAN, no medical certificate and no photo capture here.
     The document number is typed because it is a fact the register needs, exactly as the public form asks for
     it; nothing is rendered back from the vault. --}}
<div data-alta-staff-fields class="rounded-xl border border-line bg-surface-alt p-4 dark:border-slate-700 dark:bg-slate-800">
    <div class="grid gap-3 sm:grid-cols-2">
        <div>
            <label for="alta-first-name" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Nombre') }}</label>
            <input id="alta-first-name" type="text" wire:model="altaForm.first_name" autocomplete="off"
                   class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
            @error('altaForm.first_name') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="alta-last-name" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Apellidos') }}</label>
            <input id="alta-last-name" type="text" wire:model="altaForm.last_name" autocomplete="off"
                   class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
            @error('altaForm.last_name') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="alta-dob" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Fecha de nacimiento') }}</label>
            <input id="alta-dob" type="date" wire:model="altaForm.date_of_birth"
                   class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
            @error('altaForm.date_of_birth') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="alta-email-staff" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Email') }}</label>
            <input id="alta-email-staff" type="email" inputmode="email" wire:model="altaForm.email" autocomplete="off"
                   class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
            @error('altaForm.email') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="alta-doc-type" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Tipo de documento') }}</label>
            <select id="alta-doc-type" wire:model="altaForm.document_type"
                    class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                <option value="">{{ __('Elige…') }}</option>
                @foreach (\App\Enums\IdDocumentType::cases() as $type)
                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                @endforeach
            </select>
            @error('altaForm.document_type') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="alta-doc-number" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Número de documento') }}</label>
            <input id="alta-doc-number" type="text" wire:model="altaForm.document_number" autocomplete="off"
                   class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
            @error('altaForm.document_number') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="alta-phone" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Teléfono') }}</label>
            <input id="alta-phone" type="tel" inputmode="tel" wire:model="altaForm.phone" autocomplete="off"
                   class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
            @error('altaForm.phone') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="alta-avalador" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Avalador (nombre o nº)') }}</label>
            <input id="alta-avalador" type="text" wire:model="altaForm.avalador_ref" autocomplete="off"
                   class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
            @error('altaForm.avalador_ref') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
        </div>
        <div class="sm:col-span-2">
            <label for="alta-address" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Dirección') }}</label>
            <input id="alta-address" type="text" wire:model="altaForm.address" autocomplete="off"
                   class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
            @error('altaForm.address') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
        </div>
    </div>

    {{-- THE PART THAT IS NOT A UX QUESTION.

         The facts above are the same facts whoever types them. The consent is not: `SubmitApplication` stamps
         a versioned consent text and locale, and that record is the club's evidence that the applicant agreed
         to the processing of their data — including Article 9 health data. A member of staff ticking it on
         someone's behalf turns a record of consent GIVEN into the club's assertion that it WAS.

         So this route does not produce the public form's artefact and does not pretend to: the consent row is
         stamped PAPER and names the operator who recorded it. Choosing to type it here IS that choice, which
         is why the confirmation below is explicit and has no default. --}}
    <div class="mt-4 rounded-xl border border-warning/40 bg-warning/10 p-3">
        <label class="flex min-h-11 items-start gap-3 text-sm">
            <input type="checkbox" wire:model="altaConsentHeld" data-alta-consent-held
                   class="mt-0.5 h-5 w-5 shrink-0 rounded border-line text-brand focus:ring-brand">
            <span>
                <span class="block font-semibold">{{ __('El club conserva su consentimiento firmado') }}</span>
                <span class="block text-xs text-ink-muted dark:text-slate-400">{{ __('Se registrará como consentimiento en papel, a tu nombre. No equivale a que el socio lo acepte en pantalla: si puede hacerlo, entrégale la tablet.') }}</span>
            </span>
        </label>
        @error('altaConsentHeld') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
    </div>

    <button type="button" wire:click="submitStaffAlta" data-alta-staff-submit
            class="mt-4 h-14 w-full rounded-xl bg-brand text-base font-semibold text-white transition hover:bg-brand-dark">
        {{ __('Crear la solicitud') }}
    </button>
</div>
