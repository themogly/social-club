{{-- The applications that came back and are waiting to become members. They belong on this
     surface rather than the screen behind it: they ARE sign-ups in progress, and 207's hub
     alert opens straight onto them. --}}
@php $pending = $this->pendingAltaApplications(); @endphp
@if ($pending->isNotEmpty())
    <div class="pt-2">
        <p class="text-xs font-medium uppercase tracking-wide text-ink-muted dark:text-slate-400">{{ __('Solicitudes pendientes de revisar') }}</p>
        <ul data-alta-pending class="mt-2 divide-y divide-line overflow-hidden rounded-xl border border-line dark:divide-slate-800 dark:border-slate-800">
            @foreach ($pending as $application)
                @php $p = $application->payload ?? []; @endphp
                <li>
                    <button type="button" wire:click="reviewAltaApplication('{{ $application->id }}')" class="flex min-h-11 w-full items-center justify-between gap-3 bg-surface px-4 py-3 text-left text-sm transition hover:bg-surface-alt dark:bg-slate-900 dark:hover:bg-slate-800">
                        <span class="min-w-0 truncate">{{ trim(($p['first_name'] ?? '').' '.($p['last_name'] ?? '')) ?: ($application->applicant_email ?? __('Solicitud')) }}</span>
                        <span class="shrink-0 text-xs text-ink-muted dark:text-slate-400">{{ $application->submitted_at?->format('d/m/Y') }}</span>
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
@endif
