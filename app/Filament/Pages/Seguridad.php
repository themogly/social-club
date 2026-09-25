<?php

namespace App\Filament\Pages;

use App\Actions\Lockdown\InitiateLockdown;
use App\Actions\Lockdown\ReactivateOrganisation;
use App\Actions\RecordAuditLog;
use App\Actions\UnlockOperator;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\OrganisationLockdown;
use App\Models\User;
use App\Support\ActiveScope;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Prompt 121 — the admin face of the panic lockdown: trip it, rehearse it (drill), end a rehearsal, and see the
 * history that evidences "locked at HH:MM by whom, reactivated by which path". During a REAL lockdown the panel
 * is blocked, so this page is for BEFORE and for drills — a real one is lifted only off-terminal (owner link),
 * by the time-delay, or by the break-glass CLI. The runbook lives in the Manual.
 */
class Seguridad extends Page
{
    protected string $view = 'filament.pages.seguridad';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?string $slug = 'seguridad';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ($user->can('lockdown.manage') || $user->can('lockdown.initiate'));
    }

    public static function getNavigationLabel(): string
    {
        return __('Seguridad');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Sistema');
    }

    public function getTitle(): string
    {
        return __('Seguridad');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('panic')
                ->label(__('Activar bloqueo de seguridad'))
                ->icon(Heroicon::OutlinedLockClosed)
                ->color('danger')
                ->visible(fn (): bool => auth()->user()?->can('lockdown.initiate') ?? false)
                ->requiresConfirmation()
                ->modalHeading(__('Activar el bloqueo de seguridad'))
                ->modalDescription(__('Cerrará el club entero de inmediato. Solo se reactiva desde el enlace enviado a los propietarios, por el plazo automático o por línea de comandos.'))
                ->action(fn () => $this->trip(isDrill: false)),

            Action::make('drill')
                ->label(__('Simulacro'))
                ->icon(Heroicon::OutlinedBeaker)
                ->color('warning')
                ->visible(fn (): bool => (auth()->user()?->can('lockdown.manage') ?? false) && ! $this->activeLockdown())
                ->requiresConfirmation()
                ->modalDescription(__('Un simulacro cierra las pantallas como el bloqueo real, pero podrás terminarlo aquí mismo.'))
                ->action(fn () => $this->trip(isDrill: true)),

            Action::make('endDrill')
                ->label(__('Terminar simulacro'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('gray')
                ->visible(fn (): bool => (auth()->user()?->can('lockdown.manage') ?? false) && (bool) $this->activeLockdown()?->is_drill)
                ->action(function (): void {
                    $lockdown = $this->activeLockdown();
                    $user = auth()->user();
                    if ($lockdown !== null && $lockdown->is_drill && $user instanceof User) {
                        (new ReactivateOrganisation)->handle($lockdown, 'drill_ended', $user);
                        Notification::make()->title(__('Simulacro terminado'))->success()->send();
                    }
                }),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'active' => $this->activeLockdown(),
            'history' => OrganisationLockdown::query()->withoutGlobalScopes()
                ->where('organisation_id', app(ActiveScope::class)->organisationId())
                ->latest('locked_at')->limit(10)->get(),
            'pinBuckets' => $this->pinBuckets(),
            'canClearPinLockouts' => auth()->user()?->can('staff.manage') ?? false,
        ];
    }

    /**
     * The PIN throttle's state per bucket — every sede plus the no-sede terminal (prompt 235).
     *
     * Gated on `staff.manage`, NOT a new permission: clearing a lockout is an exercise of authority over who
     * may work the counter, which is exactly what that permission already means — and no new permission means
     * prompt 214's code-declared matrix is untouched. `statusFor()` degrades to `available: false` on a cache
     * outage, so this section shows "estado no disponible" rather than 503ing the page (124).
     *
     * @return list<array{key: string, label: string, status: array{available: bool, locked: bool, seconds: int, attempts: int, strikes: int}}>
     */
    private function pinBuckets(): array
    {
        if (! (auth()->user()?->can('staff.manage') ?? false)) {
            return [];
        }

        $unlock = new UnlockOperator;
        $buckets = [];

        foreach (Location::query()->where('active', true)->orderBy('name')->get() as $location) {
            $buckets[] = [
                'key' => 'counter-pin:'.$location->id,
                'label' => (string) $location->name,
                'status' => $unlock->statusFor('counter-pin:'.$location->id),
            ];
        }

        // The 'none' bucket: a terminal whose operator has not adopted a sede yet still throttles (the key
        // IdentifiesOperator builds falls back to 'none'), so it must be listed and clearable like the rest.
        $buckets[] = [
            'key' => 'counter-pin:none',
            'label' => __('Sin sede'),
            'status' => $unlock->statusFor('counter-pin:none'),
        ];

        return $buckets;
    }

    /**
     * Clear one bucket's lockout — attempts, lockout AND strikes (prompt 235).
     *
     * Strikes too, deliberately: the responsable clearing it is vouching for the terminal, so the escalation
     * resets and the next lockout starts again at 60s. Audited with the counts it wiped, so the log says what
     * was forgiven and not only that something was.
     */
    public function clearPinLockout(string $bucketKey): void
    {
        $user = auth()->user();

        // The SERVER refusal, not a hidden button: a crafted Livewire call from a lockdown.initiate-only
        // session must be refused here.
        abort_unless($user instanceof User && $user->can('staff.manage'), 403);

        // Only keys this screen hands out — never an arbitrary cache key.
        $location = null;
        if ($bucketKey !== 'counter-pin:none') {
            $location = Location::query()->where('active', true)->find((string) str($bucketKey)->after('counter-pin:'));
            abort_if($location === null, 404);
        }

        $wiped = (new UnlockOperator)->clearLockout($bucketKey);

        (new RecordAuditLog)->handle('counter.pin.lockout.cleared', $location, $wiped, [
            'bucket' => $bucketKey,
            'cleared_by' => $user->id,
        ]);

        Notification::make()->title(__('Bloqueo del PIN limpiado'))->success()->send();
    }

    private function activeLockdown(): ?OrganisationLockdown
    {
        $organisationId = app(ActiveScope::class)->organisationId();

        return $organisationId !== null ? OrganisationLockdown::active($organisationId) : null;
    }

    private function trip(bool $isDrill): void
    {
        $user = auth()->user();
        $organisationId = app(ActiveScope::class)->organisationId();
        $organisation = $organisationId !== null ? Organisation::query()->withoutGlobalScopes()->find($organisationId) : null;

        if ($organisation === null || ! $user instanceof User) {
            return;
        }

        (new InitiateLockdown)->handle($organisation, ['actor' => $user, 'is_drill' => $isDrill]);

        // A real lockdown blocks this very page on the next request; a drill lets the owner back through.
        if ($isDrill) {
            Notification::make()->title(__('Simulacro iniciado'))->warning()->send();
            $this->redirect(static::getUrl());
        } else {
            $this->redirect('/');
        }
    }
}
