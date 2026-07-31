<x-layouts.socio :title="__('Solicitud de alta')" :nav="false">
    @php($input = 'w-full rounded-lg border border-line bg-surface px-3 py-2.5 text-ink outline-none focus:border-brand focus:ring-2 focus:ring-brand/30 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100')
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

            <form method="POST" action="{{ route('socio.application.store', ['token' => $token]) }}"
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

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="first_name">{{ __('Nombre') }}</label>
                        <input id="first_name" name="first_name" type="text" required value="{{ old('first_name', data_get($payload, 'first_name')) }}" class="{{ $input }}">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="last_name">{{ __('Apellidos') }}</label>
                        <input id="last_name" name="last_name" type="text" required value="{{ old('last_name', data_get($payload, 'last_name')) }}" class="{{ $input }}">
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium" for="email">{{ __('Correo electrónico') }}</label>
                    <input id="email" name="email" type="email" required inputmode="email" value="{{ old('email', data_get($payload, 'email')) }}" class="{{ $input }}">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium" for="phone">{{ __('Teléfono') }}</label>
                    <input id="phone" name="phone" type="tel" inputmode="tel" value="{{ old('phone', data_get($payload, 'phone')) }}" class="{{ $input }}">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium" for="date_of_birth">{{ __('Fecha de nacimiento') }}</label>
                    <input id="date_of_birth" name="date_of_birth" type="date" required value="{{ old('date_of_birth', data_get($payload, 'date_of_birth')) }}" class="{{ $input }}">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="document_type">{{ __('Tipo de documento') }}</label>
                        <select id="document_type" name="document_type" required class="{{ $input }}">
                            @foreach (\App\Enums\IdDocumentType::cases() as $type)
                                <option value="{{ $type->value }}" @selected(old('document_type', data_get($payload, 'document_type')) === $type->value)>{{ $type->value }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="document_number">{{ __('Nº documento') }}</label>
                        <input id="document_number" name="document_number" type="text" required value="{{ old('document_number', data_get($payload, 'document_number')) }}" class="{{ $input }}">
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium" for="address">{{ __('Dirección') }}</label>
                    <input id="address" name="address" type="text" value="{{ old('address', data_get($payload, 'address')) }}" class="{{ $input }}">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="declared_monthly_g">{{ __('Consumo mensual (g)') }}</label>
                        <input id="declared_monthly_g" name="declared_monthly_g" type="number" step="0.01" min="0" value="{{ old('declared_monthly_g', $declaredG) }}" class="{{ $input }}">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="avalador_member_no">{{ __('Nº socio/a avalador/a') }}</label>
                        <input id="avalador_member_no" name="avalador_member_no" type="text" value="{{ old('avalador_member_no') }}" class="{{ $input }}">
                    </div>
                </div>

                <label class="flex items-start gap-2 rounded-lg bg-surface-alt p-3 text-sm dark:bg-slate-950">
                    <input type="checkbox" name="is_therapeutic" value="1" @checked(old('is_therapeutic', data_get($payload, 'is_therapeutic'))) class="mt-0.5 h-5 w-5 rounded border-line text-brand dark:border-slate-600 dark:bg-slate-900">
                    <span class="text-ink-muted dark:text-slate-300">{{ __('Uso terapéutico (podré aportar certificado médico)') }}</span>
                </label>

                <label class="flex items-start gap-2 rounded-lg bg-surface-alt p-3 text-sm dark:bg-slate-950">
                    <input type="checkbox" name="consent" value="1" required @checked(old('consent')) class="mt-0.5 h-5 w-5 rounded border-line text-brand dark:border-slate-600 dark:bg-slate-900">
                    <span class="text-ink-muted dark:text-slate-300">{{ __('He leído y acepto el tratamiento de mis datos y los estatutos de la asociación.') }}</span>
                </label>

                <button type="submit" class="w-full rounded-lg bg-brand px-4 py-2.5 font-semibold text-white transition hover:bg-brand-dark">
                    {{ __('Enviar solicitud') }}
                </button>
            </form>

            <p class="mt-4 text-center text-xs text-ink-muted dark:text-slate-500">{{ __('Espacio privado. No es un servicio público ni una tienda.') }}</p>
        @endif
    </div>
</x-layouts.socio>
