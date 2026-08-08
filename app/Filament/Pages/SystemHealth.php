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
 * age (with an alert when stale) and the queue depth and dead-letter count. OWNER only. Every
 * figure is live (never cached — it is exactly the state you must not stale-cache).
 *
 * NOT "backup/restore placeholders" — this docblock said so until the admin audit, long after prompt 180
 * replaced that section with a statement of fact: backups are the club's own infrastructure, and this
 * application neither performs them nor checks their state. The stale sentence is worth recording rather
 * than quietly deleting, because it is exactly how the claim propagated: it was repeated as a known gap in
 * the Phase C work order and again in the security report, on the strength of a comment nobody re-read.
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
            'auditRetentionSweep' => $health->auditRetentionSweep(),
            'messageRetentionSweep' => $health->messageRetentionSweep(),
            'importStagingSweep' => $health->importStagingSweep(),
            'queue' => $health->queue(),
            'cache' => $health->cache(),
            'permissions' => $health->permissions(),
            'mailer' => $health->mailer(),
            'documentsDisk' => $health->documentsDisk(),
            'auditRetentionDays' => $health->auditRetentionDays(),
            'dataRetentionDays' => $health->dataRetentionDays(),
        ];
    }
}
