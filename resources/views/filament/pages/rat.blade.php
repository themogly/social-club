{{--
    RAT — Registro de Actividades de Tratamiento (Art. 30 RGPD), generado en vivo desde el
    sistema (App\ViewModels\Rat). Datos de consumo/terapéuticos marcados como Art. 9.
--}}
<x-filament-panels::page>
    <div style="display:flex;justify-content:flex-end;gap:.5rem;margin-bottom:.5rem;">
        <x-filament::button wire:click="exportPdf" wire:target="exportPdf" size="sm" color="gray"
            icon="heroicon-o-document-arrow-down">
            {{ __('Exportar PDF') }}
        </x-filament::button>
    </div>

    @if ($hasArticle9)
        <div style="border:1px solid #dc2626;border-radius:.75rem;padding:.75rem 1rem;margin-bottom:1rem;display:flex;gap:.6rem;align-items:flex-start;">
            <x-filament::icon icon="heroicon-o-shield-exclamation" style="width:1.25rem;height:1.25rem;color:#dc2626;flex:none;margin-top:.1rem;" />
            <div style="font-size:.875rem;">
                <strong>{{ __('Datos de categoría especial (Art. 9 RGPD).') }}</strong>
                {{ __('El consumo de cannabis y la condición terapéutica son datos de salud: requieren consentimiento explícito, base jurídica documentada, control de acceso reforzado y EIPD (DPIA).') }}
            </div>
        </div>
    @endif

    <x-filament::section :heading="__('Responsable del tratamiento')">
        <dl style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem 1.5rem;font-size:.875rem;">
            <div><dt style="opacity:.65;">{{ __('Denominación') }}</dt><dd style="font-weight:600;">{{ $controller['name'] }}</dd></div>
            <div><dt style="opacity:.65;">{{ __('Nombre legal') }}</dt><dd style="font-weight:600;">{{ $controller['legal_name'] ?? '—' }}</dd></div>
            <div><dt style="opacity:.65;">{{ __('CIF/NIF') }}</dt><dd style="font-weight:600;">{{ $controller['tax_id'] ?? '—' }}</dd></div>
            <div><dt style="opacity:.65;">{{ __('Dirección') }}</dt><dd style="font-weight:600;">{{ $controller['address'] ?? '—' }}</dd></div>
            <div><dt style="opacity:.65;">{{ __('Contacto') }}</dt><dd style="font-weight:600;">{{ $controller['contact_email'] ?? '—' }}</dd></div>
            <div><dt style="opacity:.65;">{{ __('Generado') }}</dt><dd style="font-weight:600;">{{ $generatedAt->format('d/m/Y H:i') }}</dd></div>
        </dl>
    </x-filament::section>

    <div style="display:flex;flex-direction:column;gap:1rem;margin-top:1rem;">
        @foreach ($activities as $activity)
            <x-filament::section>
                <x-slot name="heading">
                    <span style="display:inline-flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                        <span style="opacity:.6;font-variant-numeric:tabular-nums;">{{ $activity['ref'] }}</span>
                        <span>{{ $activity['name'] }}</span>
                        @if ($activity['article_9'])
                            <x-filament::badge color="danger">{{ __('Art. 9 · datos de salud') }}</x-filament::badge>
                        @endif
                    </span>
                </x-slot>

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:.9rem 1.5rem;font-size:.875rem;">
                    <div style="grid-column:1/-1;">
                        <div style="opacity:.65;">{{ __('Finalidad') }}</div>
                        <div>{{ $activity['purpose'] }}</div>
                    </div>
                    <div>
                        <div style="opacity:.65;">{{ __('Base jurídica') }}</div>
                        <div>{{ $activity['legal_basis'] }}</div>
                    </div>
                    <div>
                        <div style="opacity:.65;">{{ __('Categorías de datos') }}</div>
                        <ul style="margin:.15rem 0 0 1rem;padding:0;">
                            @foreach ($activity['data_categories'] as $category)
                                <li>{{ $category }}</li>
                            @endforeach
                        </ul>
                    </div>
                    <div>
                        <div style="opacity:.65;">{{ __('Destinatarios') }}</div>
                        <div>{{ $activity['recipients'] }}</div>
                    </div>
                    <div>
                        <div style="opacity:.65;">{{ __('Transferencias') }}</div>
                        <div>{{ $activity['transfers'] }}</div>
                    </div>
                    <div style="grid-column:1/-1;">
                        <div style="opacity:.65;">{{ __('Plazo de conservación') }}</div>
                        <div>{{ $activity['retention'] }}</div>
                    </div>
                </div>
            </x-filament::section>
        @endforeach
    </div>

    <div style="margin-top:1rem;">
        <x-filament::section :heading="__('Medidas de seguridad (Art. 32 RGPD)')">
            <p style="font-size:.875rem;">{{ $security }}</p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
