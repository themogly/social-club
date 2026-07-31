<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Support\Settings;
use App\ViewModels\SystemHealth as HealthSnapshot;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Salud del sistema — the operational health panel. The failure mode of a broken scheduler
 * or a stuck queue is silence, so this makes liveness observable: the scheduler heartbeat
 * age (with an alert when stale), the queue depth and dead-letter count, and backup/restore
 * placeholders. OWNER only. Every figure is live (never cached — it is exactly the state you
 * must not stale-cache).
 */
class SystemHealth extends Page
{
    protected string $view = 'filament.pages.system-health';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?int $navigationSort = 80;

    protected static ?string $slug = 'salud-del-sistema';

    public static function getNavigationLabel(): string
    {
        return __('Salud del sistema');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Sistema');
    }

    public function getTitle(): string
    {
        return __('Salud del sistema');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole(Role::OWNER->value) ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $health = new HealthSnapshot;

        return [
            'scheduler' => $health->scheduler(),
            'expirySweep' => $health->expirySweep(),
            // Only surfaced when temporary members are enabled — otherwise the sweep is idle by design.
            'temporarySweep' => (bool) Settings::get('temporary_members_enabled', false) ? $health->temporarySweep() : null,
            'queue' => $health->queue(),
            'backups' => $health->backups(),
            'auditRetentionDays' => $health->auditRetentionDays(),
            'dataRetentionDays' => $health->dataRetentionDays(),
        ];
    }
}
