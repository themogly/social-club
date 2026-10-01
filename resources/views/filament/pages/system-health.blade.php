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
        {{-- Prompt 327 — a staging site must not send anything real: red when it could. --}}
        @if (! empty($stagingLeaks))
            <x-filament::section :heading="__('Staging no debe enviar nada real.')" icon="heroicon-o-x-circle" icon-color="danger" data-staging-leaks>
                <ul class="list-disc ps-5 text-sm text-danger-600 dark:text-danger-400">
                    @foreach ($stagingLeaks as $leak)
                        <li>{{ $leak }}</li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        {{-- Prompt 326 — edibles still counted by typed grams (no THC figure yet). Only shown when there are any. --}}
        @if ($ediblesWithoutThc !== [])
            <x-filament::section :heading="__('Comestibles sin cifra de THC')" icon="heroicon-o-exclamation-triangle" data-edibles-without-thc>
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Siguen contando con los gramos que se escribieron a mano. Edita cada uno y pon su THC por unidad (mg): a partir de ahí cuenta por la equivalencia del club.') }}</p>
                <ul class="mt-2 list-disc ps-5 text-sm">
                    @foreach ($ediblesWithoutThc as $name)
                        <li>{{ $name }}</li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

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

        {{-- Mail (prompts 145, 288). A mailer that needs an API key and lacks one fails SILENTLY — mail never arrives — so
             surface it here rather than discover it via a member's missing card. Red: mail cannot work; amber: it would
             send from a placeholder address; plus the mail that failed for good this week. Configuration check only; a
             real test send is `php artisan csc:mail-test`. --}}
        @php $mailColor = ['red' => 'danger', 'amber' => 'warning', 'green' => 'success'][$mailer['status']] ?? 'gray'; @endphp
        <x-filament::section :heading="__('Correo')" icon="heroicon-o-envelope" data-health-mail>
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;">
                <x-filament::badge :color="$mailColor" data-health-mail-status="{{ $mailer['status'] }}">
                    {{ match ($mailer['status']) { 'red' => __('No funcionará'), 'amber' => __('Revisar'), default => __('Configurado') } }}
                </x-filament::badge>
            </div>
            <dl style="font-size:.875rem;display:grid;gap:.35rem;">
                <div style="display:flex;justify-content:space-between;gap:1rem;">
                    <dt style="opacity:.65;">{{ __('Transporte') }}</dt>
                    <dd>{{ $mailer['mailer'] }}</dd>
                </div>
                <div style="display:flex;justify-content:space-between;gap:1rem;">
                    <dt style="opacity:.65;">{{ __('Remitente') }}</dt>
                    <dd>{{ $mailer['from'] !== '' ? $mailer['from'] : '—' }}</dd>
                </div>
                <div style="display:flex;justify-content:space-between;gap:1rem;">
                    <dt style="opacity:.65;">{{ __('Correos fallidos (7 días)') }}</dt>
                    <dd>{{ array_sum($mailer['failed_last_7_days']) }}</dd>
                </div>
                @foreach ($mailer['failed_last_7_days'] as $class => $count)
                    <div style="display:flex;justify-content:space-between;gap:1rem;padding-left:1rem;">
                        <dt style="opacity:.65;">{{ class_basename($class) }}</dt>
                        <dd>{{ $count }}</dd>
                    </div>
                @endforeach
            </dl>
            @if ($mailer['needs_credential'] && ! $mailer['configured'])
                <div style="margin-top:.5rem;color:#dc2626;font-size:.85rem;">
                    {{ __('El transporte de correo seleccionado necesita una clave de API y no la encuentra. El correo no se enviará hasta configurarla.') }}
                </div>
            @elseif ($mailer['status'] === 'red')
                <div style="margin-top:.5rem;color:#dc2626;font-size:.85rem;">
                    {{ __('En producción el correo se está escribiendo en el registro o descartando: no llega a nadie. Configura un transporte real.') }}
                </div>
            @elseif ($mailer['status'] === 'amber')
                <div style="margin-top:.5rem;color:#d97706;font-size:.85rem;">
                    {{ __('El remitente es una dirección de ejemplo. Configura MAIL_FROM_ADDRESS con un dominio verificado.') }}
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

        {{-- Permission drift (prompt 214). `RolePermissionSeeder` was only ever called by `csc:install`, so a
             club kept its install-day matrix for ever: a permission added to a role never arrived, and one
             REMOVED from a role was never revoked — the worse direction, because `App\Support\Permissions`
             is the file everyone reads as the source of truth for who may do what.

             It failed silently. The only symptom was an operator refused something the code grants them,
             which reads as an application bug — and that is exactly how it was reported: an OWNER told
             *"ask a manager"*. This panel is the half that reaches somebody who never sees a deploy log. --}}
        {{-- Prompt 304 — the launch latch: until `csc:launch`, the pre-launch reset may wipe the test data; after, never. --}}
        <x-filament::section :heading="__('Lanzamiento')" icon="heroicon-o-rocket-launch">
            <x-filament::badge :color="$launch['launched'] ? 'success' : 'warning'" data-launch-state="{{ $launch['launched'] ? 'launched' : 'not-launched' }}">
                {{ $launch['launched'] ? __('En marcha desde :date', ['date' => $launch['since']]) : __('Sin lanzar — datos de prueba permitidos') }}
            </x-filament::badge>
        </x-filament::section>

        <x-filament::section :heading="__('Permisos')" icon="heroicon-o-key">
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;">
                <x-filament::badge :color="$permissions['in_sync'] ? 'success' : 'danger'" data-permission-drift="{{ $permissions['in_sync'] ? 'in-sync' : 'drift' }}">
                    {{ $permissions['in_sync'] ? __('Coinciden con el código') : __('No coinciden con el código') }}
                </x-filament::badge>
            </div>
            @if ($permissions['in_sync'])
                <p style="font-size:.8rem;opacity:.75;">{{ __('Los roles de esta base de datos son exactamente los que describe el código.') }}</p>
            @else
                <ul style="font-size:.8rem;display:grid;gap:.3rem;margin-bottom:.6rem;">
                    @foreach ($permissions['lines'] as $line)
                        <li>· {{ $line }}</li>
                    @endforeach
                </ul>
                <p style="font-size:.8rem;opacity:.75;">{{ __('Ejecuta «php artisan csc:sync-permissions» en el servidor. Hasta entonces, alguien puede tener permisos que el código ya retiró, o que no le llegan.') }}</p>
            @endif
            {{-- Prompt 262 — the club's own choices (Roles y permisos): information in a neutral colour, never drift. --}}
            @if ($permissions['overrides'] !== [])
                <div data-permission-overrides style="margin-top:.6rem;">
                    <x-filament::badge color="gray">{{ trans_choice(':count permiso personalizado|:count permisos personalizados', count($permissions['overrides']), ['count' => count($permissions['overrides'])]) }}</x-filament::badge>
                    <ul style="font-size:.8rem;display:grid;gap:.2rem;margin-top:.4rem;opacity:.8;">
                        @foreach ($permissions['overrides'] as $line)
                            <li>· {{ $line }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
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
                {{-- Prompt 344 — what is true: the counter, authorisation, the panel login and every PIN keep working (permissions and
                     sign-in limits on the database); the queues wait (mail and notices go out when Redis returns). --}}
                <p data-cache-degraded style="margin-top:.6rem;font-size:.8rem;opacity:.75;">{{ __('La caché no responde. El mostrador y la autorización siguen funcionando (permisos en base de datos).') }}
                    @if ($cache['limiter_apart']) {{ __('El inicio de sesión y los PIN también (los límites de intentos están en base de datos).') }}@else {{ __('El inicio de sesión y los PIN dependen de esta caché: configura CACHE_LIMITER=database.') }}@endif
                    @if ($cache['queue_on_redis']) {{ __('Si Redis no responde, las colas tampoco procesan.') }} {{ __('Los correos y avisos salen cuando vuelva; no se pierde nada.') }}@endif</p>
            @endunless
        </x-filament::section>

        {{-- Prompt 311 — owner alerts: is Telegram set up, when did the 15-minute evaluation last run, and did any alert
             message fail for good this week. --}}
        <x-filament::section :heading="__('Avisos')" icon="heroicon-o-bell-alert">
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;">
                <x-filament::badge :color="$alerts['last_evaluation']['stale'] ? 'danger' : ($alerts['failed_last_7_days'] > 0 ? 'warning' : 'success')">
                    {{ $alerts['last_evaluation']['stale'] ? __('Sin evaluación reciente') : ($alerts['failed_last_7_days'] > 0 ? __('Revisar') : __('Funcionando')) }}
                </x-filament::badge>
            </div>
            <dl style="font-size:.875rem;display:grid;gap:.35rem;">
                <div style="display:flex;justify-content:space-between;gap:1rem;">
                    <dt style="opacity:.65;">{{ __('Telegram configurado') }}</dt>
                    <dd data-alerts-telegram>{{ $alerts['telegram'] ? __('Sí') : __('No') }}</dd>
                </div>
                <div style="display:flex;justify-content:space-between;gap:1rem;">
                    <dt style="opacity:.65;">{{ __('Última evaluación') }}</dt>
                    <dd>{{ $alerts['last_evaluation']['last_at']?->translatedFormat('j M H:i') ?? '—' }}</dd>
                </div>
                <div style="display:flex;justify-content:space-between;gap:1rem;">
                    <dt style="opacity:.65;">{{ __('Avisos fallidos (7 días)') }}</dt>
                    <dd>{{ $alerts['failed_last_7_days'] }}</dd>
                </div>
            </dl>
        </x-filament::section>

        {{-- Prompt 180 — a statement of fact, not a status.

             This section used to report "Última copia — Sin configurar / Pendiente de conectar una
             canalización de copias", fed by two Settings keys (`last_backup_at`, `last_restore_at`) that were
             not in `Settings::DEFAULTS` and that nothing in the codebase ever wrote. With prompt 160 dropped
             — the owner handles backups on his own infrastructure and no backup mechanism belongs in this
             application — nothing ever would. So the claim was permanent, and it was FALSE: backups are
             configured; the application simply has no visibility of them, which is a different statement and
             the far less damaging one. On a page titled Salud del sistema, read by an officer or an
             inspector, "not configured" is worse than saying nothing at all.

             Removing the section outright was the alternative. This says it instead, because a health page
             with a silent gap invites the same question from the other direction and the next person to
             notice may refill it with another placeholder. The wording states where RESPONSIBILITY sits and
             deliberately reports no status — the application has not checked anything and must not imply it
             has. --}}
        <x-filament::section :heading="__('Copias de seguridad')" icon="heroicon-o-server-stack">
            <p style="font-size:.875rem;">{{ __('Se gestionan fuera de la aplicación, en la infraestructura del club.') }}</p>
            <p style="font-size:.75rem;opacity:.6;margin-top:.5rem;">{{ __('Esta aplicación no las realiza ni comprueba su estado.') }}</p>
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
