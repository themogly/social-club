{{-- Salud del sistema — liveness at a glance. Alert style when the heartbeat is stale. --}}
<x-filament-panels::page>
    @php
        $fmtAge = fn (?int $s): string => $s === null
            ? __('Sin datos')
            : ($s < 60 ? $s.' s' : ($s < 3600 ? intdiv($s, 60).' min' : intdiv($s, 3600).' h'));
        $ageText = $fmtAge($scheduler['age_seconds']);
        $sweepAgeText = $fmtAge($expirySweep['age_seconds']);
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

    @if ($expirySweep['stale'])
        <div role="alert" style="border:1px solid #dc2626;background:rgba(220,38,38,.06);border-radius:.75rem;padding:.75rem 1rem;margin-bottom:1rem;display:flex;gap:.6rem;align-items:flex-start;">
            <x-filament::icon icon="heroicon-o-exclamation-triangle" style="width:1.25rem;height:1.25rem;color:#dc2626;flex:none;margin-top:.1rem;" />
            <div style="font-size:.875rem;">
                <strong>{{ __('El barrido de caducidades no se ha ejecutado.') }}</strong>
                {{ __('Aunque el planificador esté vivo, el barrido nocturno de membresías (memberships:sweep) no ha dejado señal reciente. Las membresías caducadas podrían no estar bloqueándose.') }}
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

        <x-filament::section :heading="__('Barrido de caducidades')" icon="heroicon-o-arrow-path">
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;">
                <x-filament::badge :color="$expirySweep['stale'] ? 'danger' : 'success'">
                    {{ $expirySweep['stale'] ? __('Sin ejecución reciente') : __('Al día') }}
                </x-filament::badge>
            </div>
            <dl style="font-size:.875rem;display:grid;gap:.35rem;">
                <div style="display:flex;justify-content:space-between;gap:1rem;">
                    <dt style="opacity:.65;">{{ __('Última ejecución') }}</dt>
                    <dd>{{ $expirySweep['last_at']?->format('d/m/Y H:i:s') ?? '—' }}</dd>
                </div>
                <div style="display:flex;justify-content:space-between;gap:1rem;">
                    <dt style="opacity:.65;">{{ __('Antigüedad') }}</dt>
                    <dd>{{ $sweepAgeText }}</dd>
                </div>
                <div style="display:flex;justify-content:space-between;gap:1rem;">
                    <dt style="opacity:.65;">{{ __('Umbral') }}</dt>
                    <dd>{{ intdiv($expirySweep['threshold_seconds'], 3600) }} h</dd>
                </div>
            </dl>
        </x-filament::section>

        @if ($temporarySweep ?? null)
            <x-filament::section :heading="__('Bajas de temporales')" icon="heroicon-o-user-minus">
                <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;">
                    <x-filament::badge :color="$temporarySweep['stale'] ? 'danger' : 'success'">
                        {{ $temporarySweep['stale'] ? __('Sin ejecución reciente') : __('Al día') }}
                    </x-filament::badge>
                </div>
                <dl style="font-size:.875rem;display:grid;gap:.35rem;">
                    <div style="display:flex;justify-content:space-between;gap:1rem;">
                        <dt style="opacity:.65;">{{ __('Última ejecución') }}</dt>
                        <dd>{{ $temporarySweep['last_at']?->format('d/m/Y H:i:s') ?? '—' }}</dd>
                    </div>
                    <div style="display:flex;justify-content:space-between;gap:1rem;">
                        <dt style="opacity:.65;">{{ __('Antigüedad') }}</dt>
                        <dd>{{ $fmtAge($temporarySweep['age_seconds']) }}</dd>
                    </div>
                </dl>
            </x-filament::section>
        @endif

        <x-filament::section :heading="__('Retención de auditoría')" icon="heroicon-o-shield-check">
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;">
                <x-filament::badge :color="$auditRetentionSweep['stale'] ? 'danger' : 'success'">
                    {{ $auditRetentionSweep['stale'] ? __('Sin ejecución reciente') : __('Al día') }}
                </x-filament::badge>
            </div>
            <dl style="font-size:.875rem;display:grid;gap:.35rem;">
                <div style="display:flex;justify-content:space-between;gap:1rem;">
                    <dt style="opacity:.65;">{{ __('Última ejecución') }}</dt>
                    <dd>{{ $auditRetentionSweep['last_at']?->format('d/m/Y H:i:s') ?? '—' }}</dd>
                </div>
                <div style="display:flex;justify-content:space-between;gap:1rem;">
                    <dt style="opacity:.65;">{{ __('Antigüedad') }}</dt>
                    <dd>{{ $fmtAge($auditRetentionSweep['age_seconds']) }}</dd>
                </div>
                <div style="display:flex;justify-content:space-between;gap:1rem;">
                    <dt style="opacity:.65;">{{ __('Periodo de retención') }}</dt>
                    <dd>{{ $auditRetentionDays }} {{ __('días') }}</dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section :heading="__('Retención de mensajes')" icon="heroicon-o-chat-bubble-left-right">
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;">
                <x-filament::badge :color="$messageRetentionSweep['stale'] ? 'danger' : 'success'">
                    {{ $messageRetentionSweep['stale'] ? __('Sin ejecución reciente') : __('Al día') }}
                </x-filament::badge>
            </div>
            <dl style="font-size:.875rem;display:grid;gap:.35rem;">
                <div style="display:flex;justify-content:space-between;gap:1rem;">
                    <dt style="opacity:.65;">{{ __('Última ejecución') }}</dt>
                    <dd>{{ $messageRetentionSweep['last_at']?->format('d/m/Y H:i:s') ?? '—' }}</dd>
                </div>
                <div style="display:flex;justify-content:space-between;gap:1rem;">
                    <dt style="opacity:.65;">{{ __('Antigüedad') }}</dt>
                    <dd>{{ $fmtAge($messageRetentionSweep['age_seconds']) }}</dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section :heading="__('Barrido de importaciones')" icon="heroicon-o-trash">
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;">
                <x-filament::badge :color="$importStagingSweep['stale'] ? 'danger' : 'success'">
                    {{ $importStagingSweep['stale'] ? __('Sin ejecución reciente') : __('Al día') }}
                </x-filament::badge>
            </div>
            <dl style="font-size:.875rem;display:grid;gap:.35rem;">
                <div style="display:flex;justify-content:space-between;gap:1rem;">
                    <dt style="opacity:.65;">{{ __('Última ejecución') }}</dt>
                    <dd>{{ $importStagingSweep['last_at']?->format('d/m/Y H:i:s') ?? '—' }}</dd>
                </div>
                <div style="display:flex;justify-content:space-between;gap:1rem;">
                    <dt style="opacity:.65;">{{ __('Antigüedad') }}</dt>
                    <dd>{{ $fmtAge($importStagingSweep['age_seconds']) }}</dd>
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

        {{-- Mail transport credential (prompt 145). A mailer that needs an API key and lacks one fails
             SILENTLY — mail never arrives — so surface it here rather than discover it via a member's missing
             card. Configuration check only; never a probe send. --}}
        @php $mailerBad = $mailer['needs_credential'] && ! $mailer['configured']; @endphp
        <x-filament::section :heading="__('Correo')" icon="heroicon-o-envelope">
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;">
                <x-filament::badge :color="$mailerBad ? 'danger' : 'success'">
                    {{ $mailerBad ? __('Sin credencial') : __('Configurado') }}
                </x-filament::badge>
            </div>
            <dl style="font-size:.875rem;display:grid;gap:.35rem;">
                <div style="display:flex;justify-content:space-between;gap:1rem;">
                    <dt style="opacity:.65;">{{ __('Transporte') }}</dt>
                    <dd>{{ $mailer['mailer'] }}</dd>
                </div>
            </dl>
            @if ($mailerBad)
                <div style="margin-top:.5rem;color:#dc2626;font-size:.85rem;">
                    {{ __('El transporte de correo seleccionado necesita una clave de API y no la encuentra. El correo no se enviará hasta configurarla.') }}
                </div>
            @endif
        </x-filament::section>

        {{-- Documents disk adapter (prompt 145). DOCUMENTS_DRIVER=s3 with its Flysystem adapter absent throws
             the first time an Article 9 ID scan is written — a silent go-live failure. Config check only. --}}
        <x-filament::section :heading="__('Disco de documentos')" icon="heroicon-o-lock-closed">
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;">
                <x-filament::badge :color="$documentsDisk['available'] ? 'success' : 'danger'">
                    {{ $documentsDisk['available'] ? __('Disponible') : __('Adaptador no instalado') }}
                </x-filament::badge>
            </div>
            <dl style="font-size:.875rem;display:grid;gap:.35rem;">
                <div style="display:flex;justify-content:space-between;gap:1rem;">
                    <dt style="opacity:.65;">{{ __('Controlador') }}</dt>
                    <dd>{{ $documentsDisk['driver'] }}</dd>
                </div>
            </dl>
            @unless ($documentsDisk['available'])
                <div style="margin-top:.5rem;color:#dc2626;font-size:.85rem;">
                    {{ __('El controlador del disco de documentos no tiene su adaptador instalado. No se podrán guardar documentos de identidad hasta instalarlo.') }}
                </div>
            @endunless
        </x-filament::section>

        {{-- Cache / Redis reachability (prompt 124). This page is designed to SURVIVE what it reports on:
             authorisation runs off the database store, so it renders and simply shows the cache as degraded. --}}
        <x-filament::section :heading="__('Caché')" icon="heroicon-o-circle-stack">
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;">
                <x-filament::badge :color="$cache['reachable'] ? 'success' : 'danger'">
                    {{ $cache['reachable'] ? __('Accesible') : __('No accesible') }}
                </x-filament::badge>
            </div>
            <dl style="font-size:.875rem;display:grid;gap:.35rem;">
                <div style="display:flex;justify-content:space-between;gap:1rem;">
                    <dt style="opacity:.65;">{{ __('Almacén') }}</dt>
                    <dd>{{ $cache['store'] }}</dd>
                </div>
            </dl>
            @unless ($cache['reachable'])
                <p style="margin-top:.6rem;font-size:.8rem;opacity:.75;">{{ __('La caché no responde. El mostrador y la autorización siguen funcionando (permisos en base de datos); las colas están detenidas hasta que se restablezca.') }}</p>
            @endunless
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
