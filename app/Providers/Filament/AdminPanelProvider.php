<?php

namespace App\Providers\Filament;

use App\Http\Middleware\SetLocale;
use App\Livewire\LocaleSwitcher;
use App\Livewire\LocationSwitcher;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('')
            ->brandName(config('app.name'))
            ->login()
            ->passwordReset()
            ->profile()
            // TOTP app authentication, available (not required) on any account, with
            // recovery codes. Setup + challenge UI are Filament's; the secret/recovery
            // codes persist on the User (encrypted).
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
            ])
            ->colors([
                // Brand blue #2563eb — set deliberately (never Filament's default amber).
                'primary' => Color::hex('#2563eb'),
            ])
            // Custom location switcher in the topbar (we do not use Filament tenancy —
            // the owner rollup + org-wide member search must cross locations).
            ->renderHook(
                PanelsRenderHook::TOPBAR_START,
                fn (): string => Blade::render('@livewire($component)', ['component' => LocationSwitcher::class]),
            )
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn (): string => Blade::render('@livewire($component)', ['component' => LocaleSwitcher::class]),
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                SetLocale::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
