<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Trabajos fallidos — the dead-letter view over `failed_jobs`, with Retry and Forget. The
 * failure mode of a queue is silence, so a failed job must be visible AND actionable. OWNER
 * only. Jobs are identified by their UUID (never the numeric row id), so nothing here leaks
 * a sequential identifier.
 */
class FailedJobs extends Page
{
    protected string $view = 'filament.pages.failed-jobs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationCircle;

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'trabajos-fallidos';

    public static function getNavigationLabel(): string
    {
        return __('Trabajos fallidos');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Sistema');
    }

    public function getTitle(): string
    {
        return __('Trabajos fallidos');
    }

    public function getSubheading(): ?string
    {
        return __('Cola de mensajes fallidos. Reintentar reencola el trabajo; descartar lo elimina definitivamente.');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole(Role::OWNER->value) ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** Reintentar — requeue the failed job by its UUID (queue:retry). */
    public function retry(string $uuid): void
    {
        abort_unless(static::canAccess(), 403);

        try {
            Artisan::call('queue:retry', ['id' => [$uuid]]);
            Notification::make()->title(__('Trabajo reencolado'))->success()->send();
        } catch (Throwable $e) {
            Notification::make()->title(__('No se pudo reintentar'))->body($e->getMessage())->danger()->send();
        }
    }

    /** Descartar — permanently drop the failed job by its UUID (queue:forget). */
    public function forget(string $uuid): void
    {
        abort_unless(static::canAccess(), 403);

        try {
            Artisan::call('queue:forget', ['id' => $uuid]);
            Notification::make()->title(__('Trabajo descartado'))->success()->send();
        } catch (Throwable $e) {
            Notification::make()->title(__('No se pudo descartar'))->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $rows = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->get()
            ->map(function (object $job): array {
                /** @var array<string, mixed> $payload */
                $payload = json_decode((string) ($job->payload ?? '{}'), true) ?: [];

                return [
                    'uuid' => (string) $job->uuid,
                    'connection' => (string) $job->connection,
                    'queue' => (string) $job->queue,
                    'name' => (string) (data_get($payload, 'displayName') ?? __('Trabajo')),
                    'exception' => Str::limit(strtok((string) $job->exception, "\n") ?: '', 200),
                    'failed_at' => (string) $job->failed_at,
                ];
            })
            ->all();

        return ['rows' => $rows];
    }
}
