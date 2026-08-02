<?php

namespace App\Filament\Pages;

use App\Actions\Lockdown\InitiateLockdown;
use App\Actions\Lockdown\ReactivateOrganisation;
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
        ];
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
