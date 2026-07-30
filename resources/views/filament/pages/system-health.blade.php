{{-- Salud del sistema — liveness at a glance. Alert style when the heartbeat is stale. --}}
<x-filament-panels::page>
    @php
        $ageSeconds = $scheduler['age_seconds'];
        $ageText = $ageSeconds === null
            ? __('Sin datos')
            : ($ageSeconds < 60 ? $ageSeconds.' s' : ($ageSeconds < 3600 ? intdiv($ageSeconds, 60).' min' : intdiv($ageSeconds, 3600).' h'));
    @endphp

    @if ($scheduler['stale'])
        <div role="alert" style="border:1px solid #dc2626;background:rgba(220,38,38,.06);border-radius:.75rem;padding:.75rem 1rem;margin-bottom:1rem;display:flex;gap:.6rem;align-items:flex-start;">
            <x-filament::icon icon="heroicon-o-exclamation-triangle" style="width:1.25rem;height:1.25rem;color:#dc2626;flex:none;margin-top:.1rem;" />
            <div style="font-size:.875rem;">
                <strong>{{ __('Latido obsoleto — el planificador podría estar caído.') }}</strong>
                {{ __('No se recibe una señal reciente del planificador. Comprueba que el cron (schedule:run) y el worker de colas están en marcha.') }}
            </div>
        </div>
    @endif

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem;">
        <x-filament::section :heading="__('Planificador')" icon="heroicon-o-clock">
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;">
                <x-filament::badge :color="$scheduler['stale'] ? 'danger' : 'success'">
                    {{ $scheduler['stale'] ? __('Sin señal reciente') : __('Activo') }}
                </x-filament::badge>
            </div>
            <dl style="font-size:.875rem;display:grid;gap:.35rem;">
                <div style="display:flex;justify-content:space-between;gap:1rem;">
                    <dt style="opacity:.65;">{{ __('Último latido') }}</dt>
                    <dd>{{ $scheduler['last_at']?->format('d/m/Y H:i:s') ?? '—' }}</dd>
                </div>
                <div style="display:flex;justify-content:space-between;gap:1rem;">
                    <dt style="opacity:.65;">{{ __('Antigüedad') }}</dt>
                    <dd>{{ $ageText }}</dd>
                </div>
                <div style="display:flex;justify-content:space-between;gap:1rem;">
                    <dt style="opacity:.65;">{{ __('Umbral') }}</dt>
                    <dd>{{ intdiv($scheduler['threshold_seconds'], 60) }} min</dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section :heading="__('Colas')" icon="heroicon-o-queue-list">
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;">
                <x-filament::badge :color="$queue['failed'] > 0 ? 'danger' : 'success'">
                    {{ $queue['failed'] > 0 ? __(':n fallidos', ['n' => $queue['failed']]) : __('Sin fallos') }}
                </x-filament::badge>
            </div>
            <dl style="font-size:.875rem;display:grid;gap:.35rem;">
                <div style="display:flex;justify-content:space-between;gap:1rem;">
                    <dt style="opacity:.65;">{{ __('Pendientes') }}</dt>
                    <dd>{{ $queue['pending'] }}</dd>
                </div>
                <div style="display:flex;justify-content:space-between;gap:1rem;">
                    <dt style="opacity:.65;">{{ __('Fallidos') }}</dt>
                    <dd>{{ $queue['failed'] }}</dd>
                </div>
            </dl>
            @if ($queue['failed'] > 0)
                <div style="margin-top:.6rem;">
                    <a href="{{ \App\Filament\Pages\FailedJobs::getUrl() }}" style="color:#2563eb;font-size:.85rem;">{{ __('Ver trabajos fallidos →') }}</a>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('Copias de seguridad')" icon="heroicon-o-server-stack">
            <dl style="font-size:.875rem;display:grid;gap:.35rem;">
                <div style="display:flex;justify-content:space-between;gap:1rem;">
                    <dt style="opacity:.65;">{{ __('Última copia') }}</dt>
                    <dd>{{ $backups['last_backup'] ?? __('Sin configurar') }}</dd>
                </div>
                <div style="display:flex;justify-content:space-between;gap:1rem;">
                    <dt style="opacity:.65;">{{ __('Última restauración') }}</dt>
                    <dd>{{ $backups['last_restore'] ?? __('Sin configurar') }}</dd>
                </div>
            </dl>
            <p style="font-size:.75rem;opacity:.6;margin-top:.5rem;">{{ __('Pendiente de conectar una canalización de copias.') }}</p>
        </x-filament::section>
    </div>

    <div style="margin-top:1rem;">
        <x-filament::section :heading="__('Retención')">
            <p style="font-size:.875rem;">
                {{ __('El registro de auditoría se conserva :audit días, deliberadamente más que los :data días de los datos de socio — para poder evidenciar accesos y acciones pasadas.', ['audit' => $auditRetentionDays, 'data' => $dataRetentionDays]) }}
            </p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
