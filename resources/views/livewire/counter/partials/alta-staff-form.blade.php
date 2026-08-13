{{-- The staff-typed sign-up — prompt 210's route, prompt 221's wizard.

     ONE WRITER, and that is still the whole argument: these fields are validated by
     `SubmitApplicationRequest::factRules()` — literally the public form's rules — and the record is written by
     `SubmitApplication`, the same Action the public POST calls. The age gate, the duplicate search and the
     versioned consent capture all still run in `ApproveApplication` afterwards. 221 rearranged where the
     fields are asked. It changed nothing about what happens to them.

     **ONE FILE, deliberately, and this is load-bearing.** Prompt 215's parity guard reads THIS file's bytes
     for every field bound to the alta form and compares the set against `ApplicationShape`. Splitting the four
     steps into four partials would have hidden fields from the guard that exists precisely because two
     hand-written field lists drifted. So the steps are sections of one file, gated on the current step, and a
     field can no more hide in step 3 than it could in a partial the reader never opens.

     (Do not write an EXAMPLE of a bound field in this comment. The reader is a regex over these bytes, so a
     sample binding in prose reads as a real field and fails the guard — which is exactly what it did.)

     Which field is asked on which step is NOT decided here — `SignsUpMembers::WIZARD_STEPS` decides, because
     `altaNext()` validates from the same map. Markup that disagreed with it would validate one step and
     render another.

     177's boundary holds: nothing renders a scan back from the vault. Capturing is not displaying.

     **AUTOFILL IS SUPPRESSED HERE** (prompt 231), and the reason is whose data this is. The owner's
     screenshot showed Chrome painting Email/Phone/Address white with `hawker.ben@gmail.com` in them — the
     OPERATOR's own contact details, one tap from being saved as a new member's. The applicant's own form and
     the handed-over tablet are the opposite case and get correct tokens instead; see `socio/application`.
     `autocomplete="off"` on the form is widely ignored by Chrome for recognised field types, so each field
     also carries a token Chrome has no saved value for.
 --}}
{{-- Prompt 231 compacted this step's vertical rhythm. Measured on `2306824` with the MRZ trigger visible
     (223 made it mount, and it is the ~44px that decides the fit): **506px of content in a 506px region** at
     1180×820 in ES — a fit of ZERO — and 42px clipped at 1180×760, with the reader below the fold. Nothing
     was dropped; the gaps, the label offsets and the reader block's padding gave the pixels back. --}}
<div data-alta-staff-fields class="space-y-3">

    {{-- ============ 1 · IDENTIDAD ============
         The two uploads and 179's reader live here because the reader READS the document file chosen here and
         PREFILLS four of these fields — `mountStaffMrzScan` binds its trigger to `[data-alta-scan]`, so the
         trigger and the input must render together or the control silently does nothing. --}}
    @if ($altaStep === 1)
        <div class="grid gap-2.5 sm:grid-cols-2">
            <div>
                <label for="alta-first-name" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Nombre') }}</label>
                <input id="alta-first-name" type="text" wire:model="altaForm.first_name" autocomplete="new-first-name" data-no-autofill
                       class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                @error('altaForm.first_name') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="alta-last-name" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Apellidos') }}</label>
                <input id="alta-last-name" type="text" wire:model="altaForm.last_name" autocomplete="new-last-name" data-no-autofill
                       class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                @error('altaForm.last_name') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="alta-dob" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Fecha de nacimiento') }}</label>
                <input id="alta-dob" type="date" wire:model="altaForm.date_of_birth" autocomplete="new-date-of-birth" data-no-autofill
                       class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                @error('altaForm.date_of_birth') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="alta-doc-type" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Tipo de documento') }}</label>
                <select id="alta-doc-type" wire:model="altaForm.document_type" autocomplete="off" data-no-autofill
                        class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                    <option value="">{{ __('Elige…') }}</option>
                    @foreach (\App\Enums\IdDocumentType::cases() as $type)
                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                    @endforeach
                </select>
                @error('altaForm.document_type') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label for="alta-doc-number" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Número de documento') }}</label>
                <input id="alta-doc-number" type="text" wire:model="altaForm.document_number" autocomplete="new-document-number" data-no-autofill
                       class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                @error('altaForm.document_number') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- THE TWO UPLOADS (prompt 215). The counter nags about a missing photo on three screens and the form
             staff use to create members could not capture one — the sharpest of the four omissions.

             `capture="user"` / `capture="environment"` asks a device WITH a camera to open it and is ignored by
             one without, so this is the same progressive enhancement 157 and 179 built: the file input is
             always there and the form is usable with no camera at all. Both files go through
             `SubmitApplication` to `DocumentVault` — encrypted before write, private disk, signed
             access-logged URL — whichever form uploaded them. --}}
        <div class="grid gap-2.5 sm:grid-cols-2">
            <div>
                <label for="alta-photo" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Foto (opcional)') }}</label>
                <input id="alta-photo" type="file" accept="image/*" capture="user" wire:model="altaPhoto" data-alta-photo
                       class="mt-1 block min-h-11 w-full text-sm text-ink file:mr-3 file:min-h-11 file:rounded-lg file:border-0 file:bg-brand-tint file:px-3 file:text-sm file:font-medium file:text-brand dark:text-slate-200 dark:file:bg-slate-800 dark:file:text-slate-100">
                <p class="mt-0.5 text-[11px] leading-tight text-ink-muted dark:text-slate-400">{{ __('Se compara con la persona en el mostrador. Puedes omitirla.') }}</p>
                @error('altaPhoto') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="alta-scan" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Documento de identidad (opcional)') }}</label>
                <input id="alta-scan" type="file" accept="image/*,application/pdf" capture="environment" wire:model="altaDocumentScan" data-alta-scan
                       class="mt-1 block min-h-11 w-full text-sm text-ink file:mr-3 file:min-h-11 file:rounded-lg file:border-0 file:bg-brand-tint file:px-3 file:text-sm file:font-medium file:text-brand dark:text-slate-200 dark:file:bg-slate-800 dark:file:text-slate-100">
                <p class="mt-0.5 text-[11px] leading-tight text-ink-muted dark:text-slate-400">{{ __('Se guarda cifrado y cada consulta queda registrada.') }}</p>
                @error('altaDocumentScan') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Prompt 179's ID-scan prefill (wired to this form by 215). `hidden` until the script mounts, so a
             browser that cannot run the reader never shows a control that would do nothing — and a failed read
             leaves the form exactly as it was. --}}
        <div data-alta-mrz-region class="rounded-xl border border-line bg-surface-alt px-3 py-2 dark:border-slate-700 dark:bg-slate-800">
            <button
                type="button"
                data-alta-mrz-scan
                hidden
                data-reading="{{ __('Leyendo el documento…') }}"
                data-needs-file="{{ __('Elige primero una foto del documento.') }}"
                class="inline-flex min-h-11 items-center rounded-xl border border-brand/40 bg-brand-tint px-4 text-sm font-semibold text-brand transition hover:bg-brand-tint/70 disabled:opacity-60 dark:bg-slate-900 dark:text-slate-100"
            >{{ __('Rellenar desde el documento') }}</button>
            <p data-alta-mrz-status role="status" aria-live="polite" class="text-[11px] leading-tight text-ink-muted empty:hidden dark:text-slate-400"></p>
            <p class="mt-0.5 text-[11px] leading-tight text-ink-muted dark:text-slate-400">{{ __('Del DNI o NIE, fotografía el REVERSO. Del pasaporte, la página de la foto.') }}</p>

            @if (! empty($altaMrzFilled))
                <div data-alta-mrz-filled class="mt-2 rounded-lg border border-warning/40 bg-warning/5 p-2">
                    <p class="text-[11px] font-medium text-warning">{{ __('Leído del documento. Compruébalo con el documento delante.') }}</p>
                </div>
            @endif
        </div>
    @endif

    {{-- ============ 2 · CONTACTO ============ --}}
    @if ($altaStep === 2)
        <div class="grid gap-2.5 sm:grid-cols-2">
            <div>
                <label for="alta-email-staff" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Email') }}</label>
                <input id="alta-email-staff" type="email" inputmode="email" wire:model="altaForm.email" autocomplete="new-email" data-no-autofill
                       class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                @error('altaForm.email') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="alta-phone" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Teléfono') }}</label>
                <input id="alta-phone" type="tel" inputmode="tel" wire:model="altaForm.phone" autocomplete="new-phone" data-no-autofill
                       class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                @error('altaForm.phone') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label for="alta-address" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Dirección') }}</label>
                <input id="alta-address" type="text" wire:model="altaForm.address" autocomplete="new-address" data-no-autofill
                       class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                @error('altaForm.address') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label for="alta-avalador" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Avalador (nombre o nº)') }}</label>
                <input id="alta-avalador" type="text" wire:model="altaForm.avalador_ref" autocomplete="new-avalador-ref" data-no-autofill
                       class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                @error('altaForm.avalador_ref') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
            </div>
        </div>
    @endif

    {{-- ============ 3 · MEMBRESÍA ============ --}}
    @if ($altaStep === 3)
        {{-- Uso terapéutico. Article 9 special-category data, so it is a deliberate tick with the applicant
             present, never a default. The whole row is the tap target (217's construction). --}}
        <div>
            <label class="flex min-h-11 items-center gap-3 rounded-xl border border-line bg-surface p-4 text-base dark:border-slate-700 dark:bg-slate-900">
                <input type="checkbox" wire:model="altaForm.is_therapeutic" data-alta-therapeutic
                       class="h-5 w-5 shrink-0 rounded border-line text-brand focus:ring-brand">
                <span>
                    <span class="block font-medium">{{ __('Uso terapéutico') }}</span>
                    <span class="block text-xs text-ink-muted dark:text-slate-400">{{ __('Dato de salud: márcalo solo si la persona lo declara.') }}</span>
                </span>
            </label>
            @error('altaForm.is_therapeutic') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
        </div>

        {{-- Consumo mensual estimado (prompt 215) — it becomes `declared_monthly_cg`, which the club uses for
             its cultivation forecast and which sits behind `StockCeiling::forLocation()`. Same GUIDED presets
             as the public form (prompt 97): a free number an applicant has no basis for is not a declaration,
             which is also why the design's range select maps onto it unchanged. --}}
        <div>
            @php
                $forecastOptions = array_values(array_filter((array) \App\Support\Settings::get('forecast_options_g', [30, 50, 60, 90]), 'is_numeric'));
            @endphp
            <label for="alta-declared" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Consumo mensual estimado') }}</label>
            <select id="alta-declared" wire:model="altaForm.declared_monthly_g" data-alta-declared
                    class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                <option value="">{{ __('Prefiero no indicarlo ahora') }}</option>
                @foreach ($forecastOptions as $opt)
                    <option value="{{ $opt }}">{{ __(':n g al mes', ['n' => $opt]) }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">{{ __('Alimenta la previsión de cultivo del club. Se puede cambiar después.') }}</p>
            @error('altaForm.declared_monthly_g') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
        </div>
    @endif

    {{-- ============ 4 · FIRMA ============
         Prompt 220 in prompt 221's clothes, and NOT the design's sketch of it: the design drew one combined
         consent tick over a bare canvas. The real step is the shared pad component, the real consent
         semantics, and `signature_on_application` deciding which of the two evidences the club takes. --}}
    @if ($altaStep === 4)
        @if (\App\Support\Settings::get('signature_on_application', true))
            <div class="rounded-xl border border-brand/30 bg-brand-tint p-4 dark:border-slate-700 dark:bg-slate-800">
                <x-counter.signature-pad
                    capture="saveAltaSignature"
                    clear="clearAltaSignature"
                    :stored="(bool) $altaSignaturePath"
                    :label="__('Firma del socio/a')"
                    :hint="__('Pásale la tablet: firma quien se da de alta, no tú.')"
                    class="mt-0"
                />
                @error(\App\Support\ApplicationShape::SIGNATURE_FIELD) <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
                @error('altaSignaturePath') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
            </div>
        @endif

        {{-- THE PART THAT IS NOT A UX QUESTION.

             The facts above are the same facts whoever types them. The consent is not: `SubmitApplication`
             stamps a versioned consent text and locale, and that record is the club's evidence that the
             applicant agreed to the processing of their data — including Article 9 health data. A member of
             staff ticking it on someone's behalf turns a record of consent GIVEN into the club's assertion
             that it WAS.

             So with signatures off this route does not produce the public form's artefact and does not
             pretend to: the consent row is stamped PAPER and names the operator who recorded it. Choosing to
             type it here IS that choice, which is why the confirmation is explicit and has no default. --}}
        @unless (\App\Support\Settings::get('signature_on_application', true))
            <div class="rounded-xl border border-warning/40 bg-warning/10 p-4">
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
    @endif
</div>
