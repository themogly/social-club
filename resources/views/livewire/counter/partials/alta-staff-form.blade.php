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

        {{-- Uso terapéutico. Article 9 special-category data, so it is a deliberate tick with the applicant
             present, never a default — and it was in `BLANK_ALTA_FORM`'s state with no control to set it,
             which prompt 215's field-parity guard is what found. --}}
        <div class="sm:col-span-2">
            <label class="flex min-h-11 items-center gap-3 text-sm">
                <input type="checkbox" wire:model="altaForm.is_therapeutic" data-alta-therapeutic
                       class="h-5 w-5 shrink-0 rounded border-line text-brand focus:ring-brand">
                <span>{{ __('Uso terapéutico') }}</span>
            </label>
            @error('altaForm.is_therapeutic') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
        </div>

        {{-- Consumo mensual estimado (prompt 215) — the field nobody would notice was missing. It becomes
             `declared_monthly_cg`, which the club uses for its cultivation forecast and which sits behind
             `StockCeiling::forLocation()`, so every staff-created member used to leave the number the club
             plans its legal grow against short by one. Same GUIDED presets as the public form (prompt 97):
             a free number an applicant has no basis for is not a declaration. --}}
        <div class="sm:col-span-2">
            @php
                $forecastOptions = array_values(array_filter((array) \App\Support\Settings::get('forecast_options_g', [30, 50, 60, 90]), 'is_numeric'));
            @endphp
            <label for="alta-declared" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Consumo mensual estimado') }}</label>
            <select id="alta-declared" wire:model="altaForm.declared_monthly_g" data-alta-declared
                    class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                <option value="">{{ __('Prefiero no indicarlo ahora') }}</option>
                @foreach ($forecastOptions as $opt)
                    <option value="{{ $opt }}">{{ __(':n g al mes', ['n' => $opt]) }}</option>
                @endforeach
            </select>
            @error('altaForm.declared_monthly_g') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
        </div>
    </div>

    {{-- THE TWO UPLOADS (prompt 215). The counter nags about a missing photo on three screens and the form
         staff use to create members could not capture one — the sharpest of the four omissions.

         `capture="user"` / `capture="environment"` asks a device WITH a camera to open it and is ignored by
         one without, so this is the same progressive enhancement 157 and 179 built: the file input is always
         there and the form is usable with no camera at all. Both files go through `SubmitApplication` to
         `DocumentVault` — encrypted before write, private disk, signed access-logged URL — whichever form
         uploaded them. 177 is untouched: capturing is not displaying, and nothing here renders a scan. --}}
    <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <div>
            <label for="alta-photo" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Foto (opcional)') }}</label>
            <input id="alta-photo" type="file" accept="image/*" capture="user" wire:model="altaPhoto" data-alta-photo
                   class="mt-1 block w-full text-sm text-ink file:mr-3 file:min-h-11 file:rounded-lg file:border-0 file:bg-brand-tint file:px-3 file:text-sm file:font-medium file:text-brand dark:text-slate-200 dark:file:bg-slate-800 dark:file:text-slate-100">
            <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">{{ __('Se compara con la persona en el mostrador. Puedes omitirla.') }}</p>
            @error('altaPhoto') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="alta-scan" class="block text-xs font-medium text-ink-muted dark:text-slate-400">{{ __('Documento de identidad (opcional)') }}</label>
            <input id="alta-scan" type="file" accept="image/*,application/pdf" capture="environment" wire:model="altaDocumentScan" data-alta-scan
                   class="mt-1 block w-full text-sm text-ink file:mr-3 file:min-h-11 file:rounded-lg file:border-0 file:bg-brand-tint file:px-3 file:text-sm file:font-medium file:text-brand dark:text-slate-200 dark:file:bg-slate-800 dark:file:text-slate-100">
            <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">{{ __('Se guarda cifrado y cada consulta queda registrada.') }}</p>
            @error('altaDocumentScan') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
        </div>
    </div>

    {{-- Prompt 179's ID-scan prefill, on this form too (prompt 215). The reader and the parser are 179's, and
         both were already reusable — the partial was included by the public form and nothing else, and the
         parser by one controller. What differs is only how the read arrives: the public form POSTs the raw
         zone to a tokenised route because it HAS a token; this form has no application yet, so the browser
         hands the raw string to the component.

         `hidden` until the script mounts, so a browser that cannot run the reader never shows a control that
         would do nothing — and a failed read leaves the form exactly as it was. --}}
    <div class="mt-3" data-alta-mrz-region>
        <button
            type="button"
            data-alta-mrz-scan
            hidden
            data-reading="{{ __('Leyendo el documento…') }}"
            data-needs-file="{{ __('Elige primero una foto del documento.') }}"
            class="inline-flex min-h-11 items-center rounded-xl border border-brand/40 bg-brand-tint px-4 text-sm font-semibold text-brand transition hover:bg-brand-tint/70 disabled:opacity-60 dark:bg-slate-800 dark:text-slate-100"
        >{{ __('Rellenar desde el documento') }}</button>
        <p data-alta-mrz-status role="status" aria-live="polite" class="mt-1 text-xs text-ink-muted dark:text-slate-400"></p>
        <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">{{ __('Del DNI o NIE, fotografía el REVERSO. Del pasaporte, la página de la foto.') }}</p>

        {{-- 179's reader, loaded only where this form is (prompt 215). The public form loads it the same way
             — one bundle, two hosts. --}}
        @vite('resources/js/mrz-reader.js')

        @if (! empty($altaMrzFilled))
            <div data-alta-mrz-filled class="mt-2 rounded-lg border border-warning/40 bg-warning/5 p-2">
                <p class="text-[11px] font-medium text-warning">{{ __('Leído del documento. Compruébalo con el documento delante.') }}</p>
            </div>
        @endif
    </div>

    {{-- Prompt 220 — **the signature moment.** Staff type the facts; the member signs. This is the route
         where the meaning changes most: a signature the member drew is THEIR act, and strictly stronger
         evidence than prompt 210's staff-attested paper checkbox — which is why, with the setting on, the
         checkbox below disappears and this replaces it.

         Same pad component as the dispensation and the applicant's own form. Hand the tablet over for this
         one control and take it back: no 173 handover is needed, because nothing of the club's is hidden and
         no session changes — the member draws, staff submit. --}}
    @if (\App\Support\Settings::get('signature_on_application', true))
        <div class="mt-4 rounded-xl border border-brand/30 bg-brand-tint p-3 dark:border-slate-700 dark:bg-slate-800">
            <x-counter.signature-pad
                capture="saveAltaSignature"
                clear="clearAltaSignature"
                :stored="(bool) $altaSignaturePath"
                :label="__('Firma del socio/a')"
                :hint="__('Pásale la tablet: firma quien se da de alta, no tú.')"
                class="mt-0"
            />
            @error(\App\Support\ApplicationShape::SIGNATURE_FIELD) <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
        </div>
    @endif

    {{-- THE PART THAT IS NOT A UX QUESTION.

         The facts above are the same facts whoever types them. The consent is not: `SubmitApplication` stamps
         a versioned consent text and locale, and that record is the club's evidence that the applicant agreed
         to the processing of their data — including Article 9 health data. A member of staff ticking it on
         someone's behalf turns a record of consent GIVEN into the club's assertion that it WAS.

         So this route does not produce the public form's artefact and does not pretend to: the consent row is
         stamped PAPER and names the operator who recorded it. Choosing to type it here IS that choice, which
         is why the confirmation below is explicit and has no default. --}}
    @unless (\App\Support\Settings::get('signature_on_application', true))
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
    @endunless

    <button type="button" wire:click="submitStaffAlta" data-alta-staff-submit
            class="mt-4 h-14 w-full rounded-xl bg-brand text-base font-semibold text-white transition hover:bg-brand-dark">
        {{ __('Crear la solicitud') }}
    </button>
</div>
