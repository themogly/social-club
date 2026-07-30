@props(['message' => null, 'icon' => null])

<div class="csc-empty">
    <x-filament::icon :icon="$icon ?? \Filament\Support\Icons\Heroicon::OutlinedInbox" class="csc-empty-ico" />
    <p class="csc-empty-msg">{{ $message ?? __('Sin datos') }}</p>
</div>
