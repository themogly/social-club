<x-layouts.socio :title="__('Solicitud de alta')" :nav="false">
    @php($input = \App\Support\SocioForm::FIELD)
    @php($declaredG = data_get($payload, 'declared_monthly_cg') !== null ? (float) data_get($payload, 'declared_monthly_cg') / 100 : null)

    <div class="mx-auto max-w-sm">
        <div class="mb-5 text-center">
            <img src="/socio-icons/icon-192.png" width="56" height="56" alt="" class="mx-auto h-14 w-14 rounded-2xl shadow-sm">
            <h1 class="mt-3 text-xl font-semibold">{{ __('Solicitud de alta') }}</h1>
            <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">{{ __('Completa tus datos para solicitar ser socio/a. La asociación revisará tu solicitud.') }}</p>
        </div>

        @if (session('status'))
            <div class="rounded-2xl border border-line bg-surface p-6 text-center shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <p class="text-3xl">✓</p>
                <p class="mt-2 text-sm text-ink-muted dark:text-slate-300">{{ session('status') }}</p>
            </div>
        @else
            @if ($errors->any())
                <div class="mb-4 rounded-lg bg-error/10 p-3 text-sm text-error">
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
                <p class="text-xs text-ink-muted dark:text-slate-400">
                    <span aria-hidden="true" class="font-medium text-error">*</span> {{ __('Campos obligatorios') }}
                </p>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="first_name">{{ __('Nombre') }} <x-socio.required-mark /></label>
                        <input id="first_name" name="first_name" type="text" required value="{{ old('first_name', data_get($payload, 'first_name')) }}" class="{{ $input }}">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="last_name">{{ __('Apellidos') }} <x-socio.required-mark /></label>
                        <input id="last_name" name="last_name" type="text" required value="{{ old('last_name', data_get($payload, 'last_name')) }}" class="{{ $input }}">
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium" for="email">{{ __('Correo electrónico') }} <x-socio.required-mark /></label>
                    <input id="email" name="email" type="email" required inputmode="email" value="{{ old('email', data_get($payload, 'email')) }}" class="{{ $input }}">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium" for="phone">{{ __('Teléfono') }}</label>
                    <input id="phone" name="phone" type="tel" inputmode="tel" value="{{ old('phone', data_get($payload, 'phone')) }}" class="{{ $input }}">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium" for="date_of_birth">{{ __('Fecha de nacimiento') }} <x-socio.required-mark /></label>
                    <input id="date_of_birth" name="date_of_birth" type="date" required value="{{ old('date_of_birth', data_get($payload, 'date_of_birth')) }}" class="{{ $input }}">
                    {{-- Explicit format hint (prompt 97): the native picker's displayed order follows the
                         document language, but the submitted value is always ISO — the hint removes any doubt
                         about which number is the day, since DOB drives the minimum-age check. --}}
                    <p class="mt-1 text-xs text-ink-muted dark:text-slate-500">{{ __('Formato: día / mes / año') }}</p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="document_type">{{ __('Tipo de documento') }} <x-socio.required-mark /></label>
                        <select id="document_type" name="document_type" required class="{{ $input }}">
                            @foreach (\App\Enums\IdDocumentType::cases() as $type)
                                <option value="{{ $type->value }}" @selected(old('document_type', data_get($payload, 'document_type')) === $type->value)>{{ $type->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="document_number">{{ __('Nº documento') }} <x-socio.required-mark /></label>
                        <input id="document_number" name="document_number" type="text" required value="{{ old('document_number', data_get($payload, 'document_number')) }}" class="{{ $input }}">
                    </div>
                </div>

                {{-- Optional identity photo (prompt 157). Never required — helps staff recognise the applicant
                     on arrival and shortens the first visit. The copy is honest about what it is for. --}}
                <div>
                    <label class="mb-1 block text-sm font-medium" for="photo">{{ __('Foto (opcional)') }}</label>
                    <input id="photo" name="photo" type="file" accept="image/*" capture="user"
                           class="block w-full text-sm text-ink file:mr-3 file:rounded-lg file:border-0 file:bg-brand-tint file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand dark:text-slate-200 dark:file:bg-slate-800 dark:file:text-slate-100">
                    <p class="mt-1 text-xs text-ink-muted dark:text-slate-500">{{ __('Ayuda a que te reconozcan al llegar. Se comparará contigo en el mostrador. Puedes omitirla y hacerla en la sede.') }}</p>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium" for="address">{{ __('Dirección') }}</label>
                    <input id="address" name="address" type="text" value="{{ old('address', data_get($payload, 'address')) }}" class="{{ $input }}">
                </div>

                {{-- Consumo: a GUIDED choice from the club's forecast presets (prompt 97), not a free number
                     an applicant has no basis for — it becomes the figure on their signed declaration. --}}
                @php($forecastOptions = array_values(array_filter((array) \App\Support\Settings::get('forecast_options_g', [30, 50, 60, 90]), 'is_numeric')))
                <div>
                    <label class="mb-1 block text-sm font-medium" for="declared_monthly_g">{{ __('Consumo mensual estimado') }}</label>
                    <select id="declared_monthly_g" name="declared_monthly_g" class="{{ $input }}">
                        <option value="">{{ __('Prefiero no indicarlo ahora') }}</option>
                        @foreach ($forecastOptions as $opt)
                            <option value="{{ $opt }}" @selected((string) old('declared_monthly_g', $declaredG !== null ? (int) $declaredG : '') === (string) $opt)>{{ __(':n g al mes', ['n' => $opt]) }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-ink-muted dark:text-slate-500">{{ __('Una estimación orientativa. Podrás ajustarla con la asociación.') }}</p>
                </div>

                {{-- Avalador: a NAME or a number (prompt 97) — a prospect knows the person, not the number,
                     and this is never a hard block; the association confirms the aval at review. --}}
                <div>
                    <label class="mb-1 block text-sm font-medium" for="avalador_ref">{{ __('Tu avalador/a (nombre o nº de socio/a)') }}</label>
                    <input id="avalador_ref" name="avalador_ref" type="text" value="{{ old('avalador_ref', data_get($payload, 'avalador_ref')) }}" class="{{ $input }}">
                    <p class="mt-1 text-xs text-ink-muted dark:text-slate-500">{{ __('El socio/a que te presenta. Si no lo sabes, déjalo en blanco y la asociación te orientará.') }}</p>
                </div>

                <label class="flex items-start gap-2 rounded-lg bg-surface-alt p-3 text-sm dark:bg-slate-950">
                    <input type="checkbox" name="is_therapeutic" value="1" @checked(old('is_therapeutic', data_get($payload, 'is_therapeutic'))) class="mt-0.5 h-5 w-5 rounded border-line text-brand dark:border-slate-600 dark:bg-slate-900">
                    <span class="text-ink-muted dark:text-slate-300">{{ __('Uso terapéutico (podré aportar certificado médico)') }}</span>
                </label>

                {{-- Informed consent (prompt 97): the two texts the applicant is agreeing to are SHOWN here,
                     tagged with the exact version stamped on their consent record. Two SEPARATE ticks — data
                     processing and the statutes are different agreements. --}}
                @php($consentVersion = \App\Support\ConsentText::version())
                <div class="space-y-3 rounded-lg border border-line bg-surface-alt p-3 text-sm dark:border-slate-700 dark:bg-slate-950">
                    <p class="text-xs text-ink-muted dark:text-slate-500">{{ __('Textos legales · versión :v', ['v' => $consentVersion]) }}</p>
                    @unless (\App\Support\ConsentText::isAuthoritative())
                        {{-- The honest position (prompt 153): the club is a Spanish asociación with Spanish estatutos;
                             the text below is a translation of a specific version of the authoritative Spanish. --}}
                        <p class="text-xs text-ink-muted dark:text-slate-500">{{ __('La versión auténtica de estas declaraciones está en español; esta es una traducción de la versión :v.', ['v' => $consentVersion]) }}</p>
                    @endunless

                    <div>
                        <details class="mb-2" data-consent-privacy>
                            <summary class="cursor-pointer font-medium">{{ __('Información sobre el tratamiento de tus datos') }}</summary>
                            <p class="mt-2 whitespace-pre-line text-ink-muted dark:text-slate-400">{{ \App\Support\ConsentText::privacy() }}</p>
                        </details>
                        <label class="flex items-start gap-2">
                            <input type="checkbox" name="consent_data" value="1" required @checked(old('consent_data')) class="mt-0.5 h-5 w-5 rounded border-line text-brand dark:border-slate-600 dark:bg-slate-900">
                            <span class="text-ink-muted dark:text-slate-300">{{ __('He leído y acepto el tratamiento de mis datos.') }}</span>
                        </label>
                    </div>

                    <div>
                        <details class="mb-2" data-consent-statutes>
                            <summary class="cursor-pointer font-medium">{{ __('Estatutos de la asociación') }}</summary>
                            <p class="mt-2 whitespace-pre-line text-ink-muted dark:text-slate-400">{{ \App\Support\ConsentText::statutes() }}</p>
                        </details>
                        <label class="flex items-start gap-2">
                            <input type="checkbox" name="consent_statutes" value="1" required @checked(old('consent_statutes')) class="mt-0.5 h-5 w-5 rounded border-line text-brand dark:border-slate-600 dark:bg-slate-900">
                            <span class="text-ink-muted dark:text-slate-300">{{ __('He leído y acepto los estatutos de la asociación.') }}</span>
                        </label>
                    </div>
                </div>

                {{-- What happens next (prompt 97): set the expectation before they submit. --}}
                <p class="rounded-lg bg-brand-tint/60 p-3 text-xs text-ink-muted dark:bg-slate-800/60 dark:text-slate-400">
                    {{ __('Qué ocurre después: la asociación revisará tu solicitud. Si se aprueba, recibirás por correo tu tarjeta de socio/a con un código QR para identificarte en la sede. La revisión puede tardar unos días.') }}
                </p>

                <x-button type="submit" size="md" class="w-full">{{ __('Enviar solicitud') }}</x-button>
            </form>

            {{-- On a failed submit, land the applicant ON the first problem field — not at the top of a long form
                 on a phone (prompt 155). The field ids/names match the validation keys. --}}
            @if ($errors->any())
                <script>
                    document.querySelector({{ \Illuminate\Support\Js::from('[name="'.$errors->keys()[0].'"]') }})?.focus?.();
                </script>
            @endif

            <p class="mt-4 text-center text-xs text-ink-muted dark:text-slate-500">{{ __('Espacio privado. No es un servicio público ni una tienda.') }}</p>
        @endif
    </div>
</x-layouts.socio>
