{{-- Prompt 227 — the one page in the member app that is not phone-only.

     The owner, opening an emailed invitation in desktop Chrome: *"the form is very squashed on the screen —
     can we make it take up more width."* It was: `max-w-sm` (384px) here INSIDE the layout's `max-w-md`
     (448px), two phone caps nested, so a 2560px screen showed a 384px needle in a sea of dark.

     The phone layout was never wrong — an invitation arrives by email and most applicants open it on a
     phone. It was the only layout there was. So the LAYOUT gains an opt-in and this page is the only caller:
     `max-w-3xl` (768px), not full-bleed, because the consent texts and the upload helper copy are the longest
     lines on the page and line length is a reading concern rather than a taste one. 768px keeps them near the
     65–75 characters prose wants; a full-width form would put a 2000px line of statutes on a wide monitor. --}}
<x-layouts.socio :title="__('Solicitud de alta')" :nav="false" wide>
    {{-- The layout's cap is the only cap now. --}}
    <div>
        {{-- Prompt 342 — on the club's tablet, the STAFF way out, at any moment: a staff PIN (anyone at that sede) ends the
             handover and returns to Socios. Small and muted on purpose — "not for you" to the applicant, who cannot pass
             the PIN; 173's confinement is otherwise unchanged. --}}
        @if ($handoverActive ?? false)
            <div class="mb-2 flex justify-end" x-data="{ open: @js(session()->has('handoverStaffError')) }">
                <button type="button" data-handover-staff-exit @click="open = true; $nextTick(() => $refs.staffPin?.focus())"
                        class="inline-flex min-h-11 items-center gap-1.5 rounded-lg px-3 text-xs font-medium text-ink-muted hover:text-ink dark:text-slate-400 dark:hover:text-slate-200">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 0h10.5a2.25 2.25 0 0 1 2.25 2.25v6.75a2.25 2.25 0 0 1-2.25 2.25H6.75a2.25 2.25 0 0 1-2.25-2.25v-6.75a2.25 2.25 0 0 1 2.25-2.25Z"/></svg>
                    {{ __('Personal') }}
                </button>
                <div x-show="open" x-cloak role="dialog" aria-modal="true" aria-labelledby="handover-staff-title" data-handover-staff-dialog
                     @keydown.escape.window="open = false" class="fixed inset-0 z-50 flex items-center justify-center bg-ink/40 p-4">
                    <form method="POST" action="{{ route('socio.application.staff', ['token' => $token]) }}" @click.outside="open = false"
                          class="w-full max-w-xs rounded-2xl border border-line bg-surface p-5 text-left shadow-xl dark:border-slate-800 dark:bg-slate-900">
                        @csrf
                        <h2 id="handover-staff-title" class="text-base font-semibold">{{ __('Recuperar el mostrador') }}</h2>
                        <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">{{ __('Solo personal del club. La solicitud no se envía y el enlace sigue valiendo.') }}</p>
                        <label for="staff-pin" class="mt-3 block text-sm font-medium">{{ __('PIN') }}</label>
                        {{-- Text with the dots drawn by CSS, not type=password: no password manager offers to fill it. --}}
                        <input id="staff-pin" name="pin" x-ref="staffPin" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" maxlength="8" required
                               style="-webkit-text-security: disc;" class="{{ \App\Support\SocioForm::FIELD }} mt-1 tracking-widest">
                        @if (session('handoverStaffError'))
                            <p role="alert" class="mt-2 text-sm font-medium text-error">{{ session('handoverStaffError') }}</p>
                        @endif
                        <div class="mt-4 flex justify-end gap-2">
                            <x-button type="button" size="sm" variant="secondary" @click="open = false">{{ __('Cancelar') }}</x-button>
                            <x-button type="submit" size="sm" data-handover-staff-submit>{{ __('Recuperar') }}</x-button>
                        </div>
                    </form>
                </div>
            </div>
        @endif

        <div class="mb-5 text-center">
            <img src="/socio-icons/icon-192.png" width="56" height="56" alt="" class="mx-auto h-14 w-14 rounded-2xl shadow-sm">
            <h1 class="mt-3 text-xl font-semibold">{{ __('Solicitud de alta') }}</h1>
            <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">{{ __('Completa tus datos para solicitar ser socio/a. La asociación revisará tu solicitud.') }}</p>
        </div>

        @if (session('status') || ($submitted ?? false))
            {{-- Prompt 249 — the received state. Shown on the submit redirect (session('status')) AND on any
                 reload of a submitted form ($submitted, from the controller): the same card, no form and no
                 payload, so the previous applicant's name, document and e-mail never re-appear to whoever
                 holds the tablet next. --}}
            <div class="rounded-2xl border border-line bg-surface p-6 text-center shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <p class="text-3xl">✓</p>
                @if ($handoverActive ?? false)
                    {{-- On the club's tablet: the counter's copy and the one control on this screen — a way
                         back to the counter. It leads only to the surface, which offers a PIN pad the applicant
                         cannot pass (173's construction), so it is safe to put in front of them. Nothing about
                         e-mail or "unos días": the operator is about to approve at the counter. --}}
                    <p class="mt-2 text-sm text-ink-muted dark:text-slate-300">{{ __('Gracias. Devuelve la tablet al personal para terminar el alta.') }}</p>
                    <a href="{{ route('counter.checkin') }}" data-handover-return
                       class="mt-5 inline-flex min-h-[2.75rem] w-full items-center justify-center rounded-lg bg-brand px-5 text-sm font-semibold text-white transition hover:bg-brand-dark">
                        {{ __('Devolver la tablet al personal') }}
                    </a>
                @else
                    {{-- An emailed invite on their own phone: today's thank-you, no button. Corrections go
                         through the club, as the "qué ocurre después" copy already said. --}}
                    <p class="mt-2 text-sm text-ink-muted dark:text-slate-300">{{ session('status') ?? __('¡Gracias! Hemos recibido tu solicitud. La asociación la revisará y, si se aprueba, recibirás por correo tu tarjeta de socio/a con un código QR para identificarte. La revisión puede tardar unos días.') }}</p>
                @endif
            </div>
        @else
            @php($input = \App\Support\SocioForm::FIELD)
            @php($declaredG = data_get($payload, 'declared_monthly_cg') !== null ? intdiv((int) data_get($payload, 'declared_monthly_cg'), 100) : null)
            {{-- The summary stays (it is the fastest way to see everything at once) but it is now announced:
                 role="alert" fires it on return from a failed submit, and each message is ALSO on its own
                 field via <x-socio.field-error> (a11y audit). --}}
            @if ($errors->any())
                <div role="alert" class="mb-4 rounded-lg bg-error/10 p-3 text-sm text-error">
                    <p class="mb-1 font-semibold">{{ __('Revisa estos campos:') }}</p>
                    <ul class="list-inside list-disc space-y-0.5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('socio.application.store', ['token' => $token]) }}" enctype="multipart/form-data"
                  class="space-y-3 rounded-2xl border border-line bg-surface p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                @csrf

                {{-- Spam mitigation (prompt 38): a honeypot hidden from people + a signed render
                     timestamp. Both are checked server-side and, if tripped, the submission is
                     discarded silently — see App\Support\ApplicationSpamGuard. --}}
                <div aria-hidden="true" class="hidden">
                    <label for="{{ \App\Support\ApplicationSpamGuard::HONEYPOT }}">{{ __('Deja este campo en blanco') }}</label>
                    <input id="{{ \App\Support\ApplicationSpamGuard::HONEYPOT }}" name="{{ \App\Support\ApplicationSpamGuard::HONEYPOT }}"
                           type="text" tabindex="-1" autocomplete="off" value="">
                </div>
                <input type="hidden" name="{{ \App\Support\ApplicationSpamGuard::TIMESTAMP }}" value="{{ $formToken }}">

                {{-- The required-field convention, stated in instructions (WCAG 3.3.2, prompt 155): fields marked
                     with * are required. The programmatic signal lives on each input's `required` attribute. --}}
                {{-- Prompt 231 — autofill HELPS here, so the fields say what they are. This form and the
                     handed-over tablet are the one place a person is typing their OWN details; the staff
                     wizard is the opposite case and suppresses it, because there the browser would offer the
                     operator's contact details for somebody else's record. Whose data the form holds is what
                     decides which way it goes. --}}
                <p class="text-xs text-ink-muted dark:text-slate-400">
                    <span aria-hidden="true" class="font-medium text-error">*</span> {{ __('Campos obligatorios') }}
                </p>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="first_name">{{ __('Nombre') }} <x-socio.required-mark /></label>
                        <input id="first_name" name="first_name" autocomplete="given-name" @error('first_name') aria-invalid="true" aria-describedby="first_name-error" @enderror type="text" required value="{{ old('first_name', data_get($payload, 'first_name') ?: ($prefill['first_name'] ?? null)) }}" class="{{ $input }}">
                    <x-socio.field-error name="first_name" />
                        @include('socio.partials.mrz-confirm', ['field' => 'first_name'])
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="last_name">{{ __('Apellidos') }} <x-socio.required-mark /></label>
                        <input id="last_name" name="last_name" autocomplete="family-name" @error('last_name') aria-invalid="true" aria-describedby="last_name-error" @enderror type="text" required value="{{ old('last_name', data_get($payload, 'last_name') ?: ($prefill['last_name'] ?? null)) }}" class="{{ $input }}">
                    <x-socio.field-error name="last_name" />
                        @include('socio.partials.mrz-confirm', ['field' => 'last_name'])
                    </div>
                </div>

                {{-- Paired from `md:` (prompt 227). Below it this is one column, byte-identical to the
                     phone layout — the grid simply has one track. Each field keeps its own cell, so its
                     `<x-socio.field-error>` and its MRZ confirmation stay under the field they belong to,
                     and DOM order is the reading order, so tab order needs no `tabindex`. --}}
                <div class="grid gap-3 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="email">{{ __('Correo electrónico') }} <x-socio.required-mark /></label>
                        <input id="email" name="email" autocomplete="email" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror type="email" required inputmode="email" value="{{ old('email', data_get($payload, 'email')) }}" class="{{ $input }}">
                        <x-socio.field-error name="email" />
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium" for="phone">{{ __('Teléfono') }}</label>
                        <input id="phone" name="phone" autocomplete="tel" @error('phone') aria-invalid="true" aria-describedby="phone-error" @enderror type="tel" inputmode="tel" value="{{ old('phone', data_get($payload, 'phone')) }}" class="{{ $input }}">
                        <x-socio.field-error name="phone" />
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium" for="date_of_birth">{{ __('Fecha de nacimiento') }} <x-socio.required-mark />
                        {{-- Prompt 342 — the format, always visible: an empty date field must never look filled in. --}}
                        <span class="ml-1 text-xs font-normal text-ink-muted dark:text-slate-400">{{ __('dd/mm/aaaa') }}</span></label>
                    {{-- `data-empty-date`: while empty (required, so :invalid) Safari's greyed "today" is hidden (app CSS), so the
                         field reads as empty. Kept a single native date field: 128/179's camera prefill and its confirm fill it. --}}
                    <input id="date_of_birth" name="date_of_birth" data-empty-date autocomplete="bday" @error('date_of_birth') aria-invalid="true" aria-describedby="date_of_birth-error" @enderror type="date" required value="{{ old('date_of_birth', data_get($payload, 'date_of_birth') ?: ($prefill['date_of_birth'] ?? null)) }}" class="{{ $input }}">
                    <x-socio.field-error name="date_of_birth" />
                        @include('socio.partials.mrz-confirm', ['field' => 'date_of_birth'])
                    {{-- Explicit format hint (prompt 97): the native picker's displayed order follows the
                         document language, but the submitted value is always ISO — the hint removes any doubt
                         about which number is the day, since DOB drives the minimum-age check. --}}
                    <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">{{ __('Formato: día / mes / año') }}</p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="document_type">{{ __('Tipo de documento') }} <x-socio.required-mark /></label>
                        <select id="document_type" name="document_type" @error('document_type') aria-invalid="true" aria-describedby="document_type-error" @enderror required class="{{ $input }}">
                            @foreach (\App\Enums\IdDocumentType::cases() as $type)
                                <option value="{{ $type->value }}" @selected(old('document_type', data_get($payload, 'document_type') ?: ($prefill['document_type'] ?? null)) === $type->value)>{{ $type->label() }}</option>
                            @endforeach
                        </select>
                    <x-socio.field-error name="document_type" />
                        @include('socio.partials.mrz-confirm', ['field' => 'document_type'])
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="document_number">{{ __('Nº documento') }} <x-socio.required-mark /></label>
                        <input id="document_number" name="document_number" @error('document_number') aria-invalid="true" aria-describedby="document_number-error" @enderror type="text" required value="{{ old('document_number', data_get($payload, 'document_number') ?: ($prefill['document_number'] ?? null)) }}" class="{{ $input }}">
                    <x-socio.field-error name="document_number" />
                        @include('socio.partials.mrz-confirm', ['field' => 'document_number'])
                    </div>
                </div>

                {{-- The two uploads, side by side from `md:` (prompt 227). They are the page's longest helper
                     copy, so pairing them is what keeps the desktop form from being a column of paragraphs —
                     and the MRZ reader stays inside the document cell, bound to the input directly above it. --}}
                <div class="grid gap-3 md:grid-cols-2">
                    {{-- Identity photo (prompt 157), REQUIRED since 348: staff compare it with the person on every card scan. Marked
                         `required` (what assistive tech announces; the hidden input sits inside its visible button, so the
                         browser's bubble points there) and enforced by the server. Originally optional — helps staff recognise the applicant
                         on arrival and shortens the first visit. The copy is honest about what it is for. --}}
                    <div>
                        {{-- Prompt 295 — Hacer foto (the front camera) or Elegir archivo, the counter's shared field. --}}
                        <x-counter.file-field id="photo" name="photo" :label="__('Foto (obligatoria)')" accept="image/*" camera="user" required />
                        <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">{{ \App\Support\DocumentUpload::helperText(__('El personal la comprobará en cada visita.')) }}</p>
                    </div>

                    {{-- Optional identity DOCUMENT (prompt 178 — 155's part B, decided by the controller: capture
                         at the counter PLUS an optional upload here). Deliberately a separate field from the photo
                         above: a face is checked against a person, a document is the compliance record, and merging
                         them would merge two purposes and two lawful bases.

                         Optional means optional — there is no `required` here and no validation rule that adds one.
                         The copy states what happens to the file (encrypted, signed link, deleted if the
                         application is never approved), because for Article 9 material that is a transparency
                         obligation, not a courtesy. --}}
                    <div>
                        {{-- Prompt 295 — Hacer foto (the back camera) or Elegir archivo; the MRZ reader below still reads
                             `#document_scan`, which is the field's own input whichever button filled it. --}}
                        {{-- Prompt 346 — which side to photograph, always visible above the buttons: it is what decides whether the
                             reader can fill anything in. --}}
                        <p data-mrz-tip class="mb-1 text-xs font-medium text-ink dark:text-slate-200">{{ __('Para rellenar tus datos automáticamente: DNI/NIE por detrás, pasaporte por la página de la foto.') }}</p>
                        <x-counter.file-field id="document_scan" name="document_scan" :label="__('Documento de identidad (opcional)')" accept="image/*,application/pdf" camera="environment" />
                        <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">{{ \App\Support\DocumentUpload::helperText(__('Foto o PDF de tu DNI, NIE o pasaporte. Se guarda cifrado, solo se abre con un enlace firmado y cada consulta queda registrada. Si tu solicitud no se aprueba, se borra. Puedes omitirlo y enseñarlo en el mostrador.')) }}</p>

                        {{-- Prompt 179 — read it here, on this device. Prompt 346: the read STARTS BY ITSELF when a photo is chosen
                             or taken (the second button was the step people never found); this button is only a retry with the
                             same photo, hidden until there is one. Everything stays `hidden` until the script mounts, so a browser
                             that cannot run the reader never shows a control that would do nothing. --}}
                        <div class="mt-3">
                            <button
                                type="button"
                                data-mrz-scan
                                hidden
                                data-reading="{{ __('Leyendo el documento…') }}"
                                data-pdf="{{ __('Para rellenar tus datos automáticamente, usa una foto en lugar de un PDF.') }}"
                                data-failed="{{ __('No hemos podido leer el documento. Fotografía la cara con las líneas de letras y «<<<» (la parte de atrás del DNI o NIE; la página de la foto del pasaporte), con buena luz y sin reflejos, o rellena los datos a mano.') }}"
                                class="inline-flex min-h-11 items-center rounded-xl border border-brand/40 bg-brand-tint px-4 text-sm font-semibold text-brand transition hover:bg-brand-tint/70 disabled:opacity-60 dark:bg-slate-800 dark:text-slate-100"
                            >{{ __('Volver a leer el documento') }}</button>
                            <p role="status" aria-live="polite" class="mt-1 flex items-start gap-2 text-xs text-ink-muted dark:text-slate-400">
                                <span data-mrz-spinner hidden aria-hidden="true" class="mt-0.5 inline-block h-3.5 w-3.5 shrink-0 animate-spin rounded-full border-2 border-brand/30 border-t-brand motion-reduce:animate-none"></span>
                                <span data-mrz-status></span>
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Address + the declared figure, two-up from `md:`.
                     **Adjacent fields only** — deliberately not the prompt's suggested pairs. It asked for
                     DOB + declared and address + avalador, which would mean MOVING `declared_monthly_g` up
                     past the document and upload fields; a grid collapses to DOM order below `md:`, so that
                     reorder would land on the phone too, and "below md nothing changes, byte-identical single
                     column" is the stronger guarantee — the phone is who this page is mostly for. So the
                     pairs are the ones already next to each other, and DOB and the avalador stay full width
                     (both carry a line of helper copy under them, which reads better spanning). --}}
                <div class="grid gap-3 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="address">{{ __('Dirección') }}</label>
                        <input id="address" name="address" autocomplete="street-address" @error('address') aria-invalid="true" aria-describedby="address-error" @enderror type="text" value="{{ old('address', data_get($payload, 'address')) }}" class="{{ $input }}">
                        <x-socio.field-error name="address" />
                    </div>

                    {{-- Consumo: a GUIDED choice from the club's forecast presets (prompt 97), not a free number
                         an applicant has no basis for — it becomes the figure on their signed declaration. --}}
                    @php($forecastOptions = array_values(array_filter((array) \App\Support\Settings::get('forecast_options_g', [30, 50, 60, 90]), 'is_numeric')))
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="declared_monthly_g">{{ __('Consumo mensual estimado') }}</label>
                        <select id="declared_monthly_g" name="declared_monthly_g" @error('declared_monthly_g') aria-invalid="true" aria-describedby="declared_monthly_g-error" @enderror class="{{ $input }}">
                            <option value="">{{ __('Prefiero no indicarlo ahora') }}</option>
                            @foreach ($forecastOptions as $opt)
                                <option value="{{ $opt }}" @selected((string) old('declared_monthly_g', $declaredG !== null ? (int) $declaredG : '') === (string) $opt)>{{ __(':n g al mes', ['n' => $opt]) }}</option>
                            @endforeach
                        </select>
                        <x-socio.field-error name="declared_monthly_g" />
                        <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">{{ __('Una estimación orientativa. Podrás ajustarla con la asociación.') }}</p>
                    </div>
                </div>

                {{-- Avalador: a NAME or a number (prompt 97) — a prospect knows the person, not the number,
                     and this is never a hard block; the association confirms the aval at review. --}}
                <div>
                    <label class="mb-1 block text-sm font-medium" for="avalador_ref">{{ __('Tu avalador/a (nombre o nº de socio/a)') }}</label>
                    <input id="avalador_ref" name="avalador_ref" @error('avalador_ref') aria-invalid="true" aria-describedby="avalador_ref-error" @enderror type="text" value="{{ old('avalador_ref', data_get($payload, 'avalador_ref')) }}" class="{{ $input }}">
                    <x-socio.field-error name="avalador_ref" />
                    <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">{{ __('El socio/a que te presenta. Si no lo sabes, déjalo en blanco y la asociación te orientará.') }}</p>
                </div>

                {{-- Prompt 217 — the same construction the two consent rows use. It had none: a bare 16×20
                     checkbox on the one phone-first page in the product. --}}
                @include('socio.partials.consent-check', [
                    'name' => 'is_therapeutic',
                    'label' => __('Uso terapéutico (podré aportar certificado médico)'),
                    'checked' => (bool) old('is_therapeutic', data_get($payload, 'is_therapeutic')),
                    'tone' => 'card',
                ])

                {{-- Informed consent (prompt 97): the two texts the applicant is agreeing to are SHOWN here,
                     tagged with the exact version stamped on their consent record. Two SEPARATE ticks — data
                     processing and the statutes are different agreements. --}}
                @php($consentVersion = \App\Support\ConsentText::version())
                <div class="space-y-3 rounded-lg border border-line bg-surface-alt p-3 text-sm dark:border-slate-700 dark:bg-slate-950">
                    <p class="text-xs text-ink-muted dark:text-slate-400">{{ __('Textos legales · versión :v', ['v' => $consentVersion]) }}</p>
                    @unless (\App\Support\ConsentText::isAuthoritative())
                        {{-- The honest position (prompt 153): the club is a Spanish asociación with Spanish estatutos;
                             the text below is a translation of a specific version of the authoritative Spanish. --}}
                        <p class="text-xs text-ink-muted dark:text-slate-400">{{ __('La versión auténtica de estas declaraciones está en español; esta es una traducción de la versión :v.', ['v' => $consentVersion]) }}</p>
                    @endunless

                    <div>
                        <details class="mb-2" data-consent-privacy>
                            {{-- Prompt 217: a disclosure the applicant must open to read what they are about to
                                 consent to. It measured 290×40 and 290×20 at 390×844 — the second one because
                                 a shorter string wraps to one line. Both clear the floor now. --}}
                            <summary class="flex min-h-11 cursor-pointer items-center font-medium">{{ __('Información sobre el tratamiento de tus datos') }}</summary>
                            <p class="mt-2 whitespace-pre-line text-ink-muted dark:text-slate-400">{{ \App\Support\ConsentText::privacy() }}</p>
                        </details>
                        @include('socio.partials.consent-check', [
                            'name' => 'consent_data',
                            'label' => __('He leído y acepto el tratamiento de mis datos.'),
                            'required' => true,
                            'checked' => (bool) old('consent_data'),
                        ])
                    </div>

                    <div>
                        <details class="mb-2" data-consent-statutes>
                            <summary class="flex min-h-11 cursor-pointer items-center font-medium">{{ __('Estatutos de la asociación') }}</summary>
                            <p class="mt-2 whitespace-pre-line text-ink-muted dark:text-slate-400">{{ \App\Support\ConsentText::statutes() }}</p>
                        </details>
                        @include('socio.partials.consent-check', [
                            'name' => 'consent_statutes',
                            'label' => __('He leído y acepto los estatutos de la asociación.'),
                            'required' => true,
                            'checked' => (bool) old('consent_statutes'),
                        ])
                    </div>
                </div>

                {{-- Prompt 220 — the applicant SIGNS the consent text, on this device, before submitting.
                     The owner: *"I want a digital signature when they sign up — whether it's handed over, or
                     they're sent an application, or staff sign them up."*

                     Placed AFTER the two consent ticks and the texts they refer to, because a signature over
                     something unread is not evidence of anything. `mode="form"` because this page is a plain
                     POST: the pad writes the drawing into a hidden field and it travels with the submission —
                     the same pad component the dispensation uses, the same vault at the far end.

                     `signature_on_application` decides whether it is required; the rule is enforced in
                     `SubmitApplication`, not by this markup. --}}
                @if (\App\Support\Settings::get('signature_on_application', true))
                    <div class="rounded-lg border border-line bg-surface-alt p-3 dark:border-slate-700 dark:bg-slate-950">
                        <x-counter.signature-pad
                            mode="form"
                            :name="\App\Support\ApplicationShape::SIGNATURE_FIELD"
                            :label="__('Tu firma')"
                            :hint="__('Firma para confirmar lo que acabas de aceptar. Se guarda cifrada con tu solicitud.')"
                            class="mt-0"
                        />
                        <x-socio.field-error :name="\App\Support\ApplicationShape::SIGNATURE_FIELD" />
                    </div>
                @endif

                {{-- What happens next (prompt 97): set the expectation before they submit. --}}
                <p class="rounded-lg bg-brand-tint/60 p-3 text-xs text-ink-muted dark:bg-slate-800/60 dark:text-slate-400">
                    {{ __('Qué ocurre después: la asociación revisará tu solicitud. Si se aprueba, recibirás por correo tu tarjeta de socio/a con un código QR para identificarte en la sede. La revisión puede tardar unos días.') }}
                </p>

                <x-button type="submit" size="md" class="w-full">{{ __('Enviar solicitud') }}</x-button>
            </form>

            {{-- Prompt 342 — on their OWN phone (never during a handover), a way to step away: nothing is sent, this
                 browser's draft goes, and the same link opens the form again until it expires. Asks first, in the page. --}}
            @unless ($handoverActive ?? false)
                <div class="mt-4 text-center" x-data="{ asking: false }">
                    <button type="button" data-application-leave x-show="! asking" @click="asking = true"
                            class="min-h-11 text-sm font-medium text-ink-muted underline underline-offset-4 hover:text-ink dark:text-slate-400">{{ __('Salir sin enviar') }}</button>
                    <form x-show="asking" x-cloak method="POST" action="{{ route('socio.application.leave', ['token' => $token]) }}"
                          class="rounded-xl border border-line bg-surface p-4 text-left dark:border-slate-800 dark:bg-slate-900">
                        @csrf
                        <p class="text-sm">{{ $application->invite_expires_at !== null
                            ? __('No se enviará nada. Puedes volver con el mismo enlace hasta el :date.', ['date' => local_datetime($application->invite_expires_at, 'd/m/Y')])
                            : __('No se enviará nada. Puedes volver con el mismo enlace.') }}</p>
                        <div class="mt-3 flex justify-end gap-2">
                            <x-button type="button" size="sm" variant="secondary" @click="asking = false">{{ __('Seguir con la solicitud') }}</x-button>
                            <x-button type="submit" size="sm" data-application-leave-confirm>{{ __('Salir sin enviar') }}</x-button>
                        </div>
                    </form>
                </div>
            @endunless

            {{-- Prompt 179 — the read post. Separate from the application form because HTML forbids nesting,
                 and it carries the MRZ TEXT only. No image is posted here: that is the whole privacy
                 argument, and a test pins it. --}}
            <form method="POST" action="{{ route('socio.application.read', ['token' => $token]) }}" data-mrz-form class="hidden">
                @csrf
                <input type="hidden" name="mrz" data-mrz-input value="">
            </form>

            {{-- Guarded exactly as the layouts guard their own @vite: a full-page GET must never 500 before
                 `npm run build`. Without the build the reader is simply absent, which is the specified
                 behaviour for a browser that cannot run it. --}}
            @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
                {{-- Prompt 232 — the Alpine runtime the signature pad needs. Until this line the pad's `x-data` was dead
                 markup on this route: Alpine only ever arrived inside Livewire's bundle, and this page loads no
                 Livewire. A stroke drew nothing, the hidden field stayed empty and the server (correctly)
                 refused the submit, so the emailed invite could not be completed at all. Loaded HERE and not
                 from the layout: this is the only socio view with directives, and the rest of the layout is the
                 member PWA. `AlpineShipsWhereItIsUsedTest` is what keeps that safe. --}}
                @vite('resources/js/socio.js')

                @vite('resources/js/mrz-reader.js')
            @endif

            {{-- On a failed submit, land the applicant ON the first problem field — not at the top of a long form
                 on a phone (prompt 155). The field ids/names match the validation keys. --}}
            @if ($errors->any())
                <script>
                    document.querySelector({{ \Illuminate\Support\Js::from('[name="'.$errors->keys()[0].'"]') }})?.focus?.();
                </script>
            @endif

            <p class="mt-4 text-center text-xs text-ink-muted dark:text-slate-400">{{ __('Espacio privado. No es un servicio público ni una tienda.') }}</p>
        @endif
    </div>
</x-layouts.socio>
