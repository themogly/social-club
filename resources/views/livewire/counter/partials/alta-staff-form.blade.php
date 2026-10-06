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
    {{-- Prompt 272 — a failed Siguiente moves focus to the first invalid field (WCAG 3.3.1); it stayed on
         Siguiente, with five unassociated messages. Keyed and rendered only while errors exist, so it fires when
         they APPEAR and not on every later render (the avalador field is live, and typing must not be yanked). --}}
    @if ($errors->any())
        <span hidden data-alta-error-focus wire:key="alta-error-focus"
              x-init="$nextTick(() => $el.closest('[data-alta-staff-fields]')?.querySelector('[aria-invalid=true]')?.focus())"></span>
    @endif

    {{-- ============ 1 · IDENTIDAD ============
         The two uploads and 179's reader live here because the reader READS the document file chosen here and
         PREFILLS four of these fields — `mountStaffMrzScan` binds its trigger to `[data-alta-scan]`, so the
         trigger and the input must render together or the control silently does nothing. --}}
    @if ($altaStep === 1)
        <div class="grid gap-2.5 sm:grid-cols-2">
            <div>
                <label for="alta-first-name" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Nombre') }}</label>
                <input id="alta-first-name" type="text" wire:model="altaForm.first_name" aria-required="true" @error('altaForm.first_name') aria-invalid="true" aria-describedby="altaForm.first_name-error" @enderror autocomplete="new-first-name" data-no-autofill
                       class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                <x-socio.field-error name="altaForm.first_name" />
                @include('livewire.counter.partials.mrz-offer', ['field' => 'first_name'])
            </div>
            <div>
                <label for="alta-last-name" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Apellidos') }}</label>
                <input id="alta-last-name" type="text" wire:model="altaForm.last_name" aria-required="true" @error('altaForm.last_name') aria-invalid="true" aria-describedby="altaForm.last_name-error" @enderror autocomplete="new-last-name" data-no-autofill
                       class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                <x-socio.field-error name="altaForm.last_name" />
                @include('livewire.counter.partials.mrz-offer', ['field' => 'last_name'])
            </div>
            <div>
                <label for="alta-dob" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Fecha de nacimiento') }}</label>
                <input id="alta-dob" type="date" wire:model="altaForm.date_of_birth" aria-required="true" @error('altaForm.date_of_birth') aria-invalid="true" aria-describedby="altaForm.date_of_birth-error" @enderror autocomplete="new-date-of-birth" data-no-autofill
                       class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                <x-socio.field-error name="altaForm.date_of_birth" />
                @include('livewire.counter.partials.mrz-offer', ['field' => 'date_of_birth'])
            </div>
            <div>
                <label for="alta-doc-type" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Tipo de documento') }}</label>
                <select id="alta-doc-type" wire:model="altaForm.document_type" aria-required="true" @error('altaForm.document_type') aria-invalid="true" aria-describedby="altaForm.document_type-error" @enderror autocomplete="off" data-no-autofill
                        class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                    <option value="">{{ __('Elige…') }}</option>
                    @foreach (\App\Enums\IdDocumentType::cases() as $type)
                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                    @endforeach
                </select>
                <x-socio.field-error name="altaForm.document_type" />
                @include('livewire.counter.partials.mrz-offer', ['field' => 'document_type'])
            </div>
            <div class="sm:col-span-2">
                <label for="alta-doc-number" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Número de documento') }}</label>
                <input id="alta-doc-number" type="text" wire:model="altaForm.document_number" aria-required="true" @error('altaForm.document_number') aria-invalid="true" aria-describedby="altaForm.document_number-error" @enderror autocomplete="new-document-number" data-no-autofill
                       class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                <x-socio.field-error name="altaForm.document_number" />
                @include('livewire.counter.partials.mrz-offer', ['field' => 'document_number'])
            </div>
        </div>

        {{-- THE TWO UPLOADS (prompt 215). The counter nags about a missing photo on three screens and the form
             staff use to create members could not capture one — the sharpest of the four omissions.

             `capture="user"` / `camera="environment"` asks a device WITH a camera to open it and is ignored by
             one without, so this is the same progressive enhancement 157 and 179 built: the file input is
             always there and the form is usable with no camera at all. Both files go through
             `SubmitApplication` to `DocumentVault` — encrypted before write, private disk, signed
             access-logged URL — whichever form uploaded them. --}}
        <div class="grid gap-2.5 sm:grid-cols-2">
            <div>
                <x-counter.file-field id="alta-photo" :label="__('Foto (obligatoria)')" wire:model="altaPhoto" accept="image/*" camera="user" data-alta-photo="" :hint="__('El personal la comprobará en cada visita.')" />
            </div>

            <div>
                <p data-mrz-tip class="mb-1 text-[11px] font-medium leading-tight text-ink dark:text-slate-200">{{ __('Para rellenar los datos automáticamente: DNI/NIE por detrás, pasaporte por la página de la foto.') }}</p>
                <x-counter.file-field id="alta-scan" :label="__('Documento de identidad (opcional)')" wire:model="altaDocumentScan" accept="image/*,application/pdf" camera="environment" data-alta-scan="" :hint="__('Se guarda cifrado y cada consulta queda registrada.')" />
            </div>
        </div>

        {{-- Prompt 179's ID-scan prefill (wired to this form by 215). `hidden` until the script mounts, so a
             browser that cannot run the reader never shows a control that would do nothing — and a failed read
             leaves the form exactly as it was. --}}
        <div data-alta-mrz-region class="rounded-xl border border-line bg-surface-alt px-3 py-2 dark:border-slate-700 dark:bg-slate-800">
            {{-- Prompt 346 — the read starts by itself when the scan is chosen or taken; this is the retry. The status line
                 is the script's (`wire:ignore`), so a morph never wipes "Leyendo…" mid-read. --}}
            <button
                type="button"
                data-alta-mrz-scan
                hidden
                data-reading="{{ __('Leyendo el documento…') }}"
                data-pdf="{{ __('Para rellenar los datos automáticamente, usa una foto en lugar de un PDF.') }}"
                data-failed="{{ __('No se ha podido leer el documento. Fotografía la cara con las líneas de letras y «<<<» (la parte de atrás del DNI o NIE; la página de la foto del pasaporte), con buena luz y sin reflejos, o escribe los datos a mano.') }}"
                class="inline-flex min-h-11 items-center rounded-xl border border-brand/40 bg-brand-tint px-4 text-sm font-semibold text-brand transition hover:bg-brand-tint/70 disabled:opacity-60 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800"
            >{{ __('Volver a leer el documento') }}</button>
            <p wire:ignore role="status" aria-live="polite" class="flex items-start gap-2 text-[11px] leading-tight text-ink-muted dark:text-slate-400">
                <span data-mrz-spinner hidden aria-hidden="true" class="mt-0.5 inline-block h-3 w-3 shrink-0 animate-spin rounded-full border-2 border-brand/30 border-t-brand motion-reduce:animate-none"></span>
                <span data-alta-mrz-status></span>
            </p>

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
                <input id="alta-email-staff" type="email" inputmode="email" wire:model="altaForm.email" aria-required="true" @error('altaForm.email') aria-invalid="true" aria-describedby="altaForm.email-error" @enderror autocomplete="new-email" data-no-autofill
                       class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                <x-socio.field-error name="altaForm.email" />
            </div>
            <div>
                <label for="alta-phone" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Teléfono') }}</label>
                <input id="alta-phone" type="tel" inputmode="tel" wire:model="altaForm.phone" @error('altaForm.phone') aria-invalid="true" aria-describedby="altaForm.phone-error" @enderror autocomplete="new-phone" data-no-autofill
                       class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                <x-socio.field-error name="altaForm.phone" />
            </div>
            <div class="sm:col-span-2">
                <label for="alta-address" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Dirección') }}</label>
                <input id="alta-address" type="text" wire:model="altaForm.address" @error('altaForm.address') aria-invalid="true" aria-describedby="altaForm.address-error" @enderror autocomplete="new-address" data-no-autofill
                       class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                <x-socio.field-error name="altaForm.address" />
            </div>
            <div class="sm:col-span-2">
                <label for="alta-avalador" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Avalador (nombre o nº)') }}</label>
                {{-- Prompt 244 — `.live` (debounced) so the field says what it FOUND before the form is submitted:
                     a name that matched nobody, or two people, was accepted as free text with nobody told
                     (prompt 60 for a lookup). Same resolver SubmitApplication stores through — see avaladorFeedback(). --}}
                <input id="alta-avalador" type="text" wire:model.live.debounce.400ms="altaForm.avalador_ref" @error('altaForm.avalador_ref') aria-invalid="true" aria-describedby="altaForm.avalador_ref-error" @enderror autocomplete="new-avalador-ref" data-no-autofill
                       class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                <x-socio.field-error name="altaForm.avalador_ref" />
                @php $avalador = $this->avaladorFeedback(); @endphp
                @if ($avalador['status'] === 'found')
                    <p data-avalador-feedback="found" class="mt-1 text-xs font-medium text-success">{{ __('Avalador: :name (:no)', ['name' => $avalador['member']->fullName(), 'no' => $avalador['member']->member_no]) }}</p>
                @elseif ($avalador['status'] === 'none')
                    <p data-avalador-feedback="none" class="mt-1 text-xs font-medium text-warning">{{ __('No se encuentra ningún socio con ese nombre.') }}</p>
                @elseif ($avalador['status'] === 'multiple')
                    <p data-avalador-feedback="multiple" class="mt-1 text-xs font-medium text-warning">{{ __('Hay varios socios con ese nombre — usa el nº.') }}</p>
                @endif
            </div>
        </div>
    @endif

    {{-- ============ 3 · MEMBRESÍA ============ --}}
    @if ($altaStep === 3)
        {{-- The membership tier (prompt 243). Chosen HERE, in the Membresía step, and CARRIED to the review so
             the operator is never asked for the cuota twice — "Elige una cuota antes de aprobar" can only
             appear if it is left blank here and blank at the review. Same `altaTierId` the review binds, same
             `altaTiers()` list; optional to advance (the review re-asks only when it was skipped). --}}
        <div>
            <label for="alta-wizard-tier" class="block text-sm font-medium text-ink-muted dark:text-slate-400">{{ __('Cuota / tier') }}</label>
            <select id="alta-wizard-tier" wire:model="altaTierId" @error('altaTierId') aria-invalid="true" aria-describedby="altaTierId-error" @enderror data-alta-wizard-tier
                    class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                <option value="">{{ __('Elige una cuota…') }}</option>
                @foreach ($this->altaTiers() as $tier)
                    <option value="{{ $tier->id }}">{{ $tier->name }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">{{ __('La confirmas al revisar, antes de dar de alta.') }}</p>
        </div>

        {{-- Uso terapéutico. Article 9 special-category data, so it is a deliberate tick with the applicant
             present, never a default. The whole row is the tap target (217's construction).

             Prompt 244 — the medical certificate (the EVIDENCE) is revealed WHEN the tick is on, so the wizard
             cannot create a therapeutic member with no evidence the admin form would have required. `x-show`,
             not `x-if` (245's rule), toggled by Alpine so it appears the instant the box is ticked; the tick's
             data still goes through `wire:model`. --}}
        <div x-data="{ therapeutic: @js((bool) ($altaForm['is_therapeutic'] ?? false)) }">
            <label class="flex min-h-11 items-center gap-3 rounded-xl border border-line bg-surface p-4 text-base dark:border-slate-700 dark:bg-slate-900">
                <input type="checkbox" wire:model="altaForm.is_therapeutic" @error('altaForm.is_therapeutic') aria-invalid="true" aria-describedby="altaForm.is_therapeutic-error" @enderror @change="therapeutic = $event.target.checked" data-alta-therapeutic
                       class="h-5 w-5 shrink-0 rounded border-line text-brand focus:ring-brand">
                <span>
                    <span class="block font-medium">{{ __('Uso terapéutico') }}</span>
                    <span class="block text-xs text-ink-muted dark:text-slate-400">{{ __('Dato de salud: márcalo solo si la persona lo declara.') }}</span>
                </span>
            </label>
            <x-socio.field-error name="altaForm.is_therapeutic" />

            <div x-show="therapeutic" x-cloak class="mt-3">
                <x-counter.file-field id="alta-medical-cert" :label="__('Certificado médico (opcional)')" wire:model="altaMedicalCert" accept="image/*,application/pdf" camera="environment" data-alta-medical-cert="" :hint="__('La prueba del uso terapéutico. Se guarda cifrada, como el documento de identidad.')" />
            </div>
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
            <select id="alta-declared" wire:model="altaForm.declared_monthly_g" @error('altaForm.declared_monthly_g') aria-invalid="true" aria-describedby="altaForm.declared_monthly_g-error" @enderror data-alta-declared
                    class="mt-1 h-12 w-full rounded-xl border border-line bg-surface px-4 text-base dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                <option value="">{{ __('Prefiero no indicarlo ahora') }}</option>
                @foreach ($forecastOptions as $opt)
                    <option value="{{ $opt }}">{{ __(':n g al mes', ['n' => $opt]) }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">{{ __('Alimenta la previsión de cultivo del club. Se puede cambiar después.') }}</p>
            <x-socio.field-error name="altaForm.declared_monthly_g" />
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
                    draft="altaSignatureDraft"
                    clear="clearAltaSignature"
                    :stored="(bool) $altaSignaturePath"
                    :label="__('Firma del socio/a')"
                    :hint="__('Pásale la tablet: firma quien se da de alta, no tú.')"
                    class="mt-0"
                />
                <x-socio.field-error :name="\App\Support\ApplicationShape::SIGNATURE_FIELD" />
                <x-socio.field-error name="altaSignaturePath" />
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
                    <input type="checkbox" wire:model="altaConsentHeld" @error('altaConsentHeld') aria-invalid="true" aria-describedby="altaConsentHeld-error" @enderror data-alta-consent-held
                           class="mt-0.5 h-5 w-5 shrink-0 rounded border-line text-brand focus:ring-brand">
                    <span>
                        <span class="block font-semibold">{{ __('El club conserva su consentimiento firmado') }}</span>
                        <span class="block text-xs text-ink-muted dark:text-slate-400">{{ __('Se registrará como consentimiento en papel, a tu nombre. No equivale a que el socio lo acepte en pantalla: si puede hacerlo, entrégale la tablet.') }}</span>
                    </span>
                </label>
                <x-socio.field-error name="altaConsentHeld" />
            </div>
        @endunless
    @endif
</div>
