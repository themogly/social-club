{{-- Prompt 310 — a PIN session's panel confirmation in its last minutes: renew it now (and come straight back here), rather
     than have the next button press sent to *Confirma tu identidad* mid-task. --}}
@php($user = auth()->user())
@if ($user instanceof \App\Models\User && \App\Support\PanelIdentity::expiresSoon($user))
    <div role="status" data-confirmation-renew-banner
         class="flex items-center justify-center gap-3 border-b border-warning-200 bg-warning-50 px-4 py-2 text-sm text-warning-800 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-200">
        <span>{{ __('Tu confirmación caduca pronto.') }}</span>
        <x-filament::link :href="\App\Filament\Pages\Auth\ConfirmIdentity::getUrl(['return' => request()->fullUrl()])" data-renew-confirmation>
            {{ __('Renovar') }}
        </x-filament::link>
    </div>
@endif
