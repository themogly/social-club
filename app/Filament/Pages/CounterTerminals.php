<?php

namespace App\Filament\Pages;

use App\Actions\Counter\RevokeCounterTerminal;
use App\Models\CounterTerminal;
use App\Models\Location;
use App\Models\User;
use App\Support\LocationSwitcher;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Prompt 289 — the registered counters (tablets): name, sede, who registered it and when, when it was last used. Revocar
 * (a lost or stolen tablet) makes its next request land on the normal login. `terminals.manage`; a manager sees and
 * revokes only their sedes' tablets.
 */
class CounterTerminals extends Page
{
    protected string $view = 'filament.pages.counter-terminals';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDeviceTablet;

    protected static ?string $slug = 'mostradores-registrados';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('terminals.manage');
    }

    public static function getNavigationLabel(): string
    {
        return __('Mostradores registrados');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Sistema');
    }

    public function getTitle(): string
    {
        return __('Mostradores registrados');
    }

    /** @return Collection<int, CounterTerminal> live terminals at the sedes this person may reach */
    public function terminals(): Collection
    {
        $user = Auth::user();
        $sedes = $user instanceof User ? app(LocationSwitcher::class)->available($user)->pluck('id')->all() : [];

        return CounterTerminal::query()->withoutGlobalScopes()->live()
            ->whereIn('location_id', $sedes)
            ->with(['location', 'registrar'])
            ->orderBy('location_id')->orderBy('name')
            ->get();
    }

    public function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label(__('Revocar'))
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('¿Revocar este mostrador?'))
            ->modalDescription(__('La tablet dejará de abrir el mostrador sin contraseña. Su próxima petición irá al inicio de sesión normal.'))
            ->action(function (array $arguments): void {
                $user = Auth::user();
                $terminal = CounterTerminal::query()->withoutGlobalScopes()->find($arguments['terminal'] ?? null);

                if (! $user instanceof User || $terminal === null) {
                    return;
                }

                try {
                    (new RevokeCounterTerminal)->handle($terminal, $user);
                    Notification::make()->title(__('Mostrador revocado'))->success()->send();
                } catch (AuthorizationException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    /** For the view: a sede's name without an extra query per row. */
    public function sedeName(?Location $location): string
    {
        return $location !== null ? (string) $location->name : '—';
    }
}
