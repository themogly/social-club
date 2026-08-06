<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Http\Middleware\SetLocale;
use App\Livewire\LocaleSwitcher;
use App\Livewire\LocationSwitcher;
use App\Support\CounterScreens;
use App\Support\InitialsAvatarProvider;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
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
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->brandName(config('app.name'))
            ->login(Login::class)   // normalises the email credential (prompt 146)
            // Staff/admins authenticate here and land on the club dashboard at "/".
            // SEAM (prompt 15): members authenticate on a SEPARATE `member` guard and
            // must be routed to the member PWA, NOT this panel. When that guard exists,
            // the redirect for a member hitting "/" belongs here (a `->authGuard()` swap
            // or a middleware that bounces `member`-guarded users to the PWA route). It is
            // deliberately NOT faked now — `User` is staff-only by construction (see
            // DECISIONS prompt 02), so nothing member-shaped can reach this panel yet.
            ->passwordReset(RequestPasswordReset::class)   // normalises the email (prompt 146)
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
            // Local initials avatar (prompt 61) — replaces Filament's default UiAvatarsProvider, which
            // sent every staff name to https://ui-avatars.com on each page load (undeclared outbound
            // personal data + a broken image on locked-down networks). No request leaves the server.
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            // Custom location switcher in the topbar (we do not use Filament tenancy —
            // the owner rollup + org-wide member search must cross locations). The owner
            // additionally gets an "All locations" rollup option (App\Support\LocationSwitcher).
            ->renderHook(
                PanelsRenderHook::TOPBAR_START,
                fn (): string => Blade::render('@livewire($component)', ['component' => LocationSwitcher::class]),
            )
            // The language toggle and help sit INSIDE the topbar-end cluster, before the user menu —
            // the GLOBAL_SEARCH_AFTER hook renders within Filament's `.fi-topbar-end` flex (its 16px
            // column-gap), whereas TOPBAR_END renders OUTSIDE it as a gapless sibling of the avatar,
            // which is what jammed "DG" + "ES/EN" + "?" into one run (prompt 143). This also returns the
            // account avatar to the far-right corner — the web-wide convention for the user menu — with
            // the language + help utilities grouped to its left.
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_AFTER,
                fn (): string => Blade::render('@livewire($component)', ['component' => LocaleSwitcher::class]),
            )
            // In-app help (prompt 92): one shared, unobtrusive affordance on EVERY panel page — a help
            // menu linking to the glossary and the per-screen guides. Content-only, static (no queries),
            // so a screen without help is visibly missing this pattern rather than each screen inventing one.
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_AFTER,
                fn (): string => Blade::render("@include('filament.help-menu')"),
            )
            // The panel's tablet pass (prompt 170) — the counter got three rounds of this (116, 130,
            // 132) and the panel had never had one. Filament's default sidebar is 320px and PERMANENTLY
            // open from 1024px up, with no toggle: every iPad in landscape, and a 12.9" in portrait,
            // sits above that line. Measured on /members before this: 243px of the table off the right
            // at 1180 and 399px at 1024, taking the row-actions column — where Ver/Editar live — with it.
            //
            // The ICON RAIL, not the fully-collapsible variant: staff move between Socios, Dispensario
            // and Caja constantly, so keeping every destination one tap away beats reclaiming a further
            // 87px they do not need. The measurements say the rail is not what breaks these tables —
            // after collapsing, /members is 12px short of fitting at 1180, not 87px. The collapsed state
            // persists per browser, which is what a fixed counter tablet wants: collapse once, stays
            // collapsed.
            ->sidebarCollapsibleOnDesktop()
            // Grouped sidebar, in operational order. Every group is deliberate; a group
            // renders only when the actor can see at least one item in it (so STAFF never
            // sees Sistema, and Informes/Documentos/Audit are omitted until they exist —
            // a shorter sidebar beats dead links).
            // Groups carry NO icon: Filament v5 forbids a group and its items both having
            // icons (the resources/counter items already carry theirs). Order is what we
            // set here; every group is deliberate.
            ->navigationGroups([
                NavigationGroup::make(fn (): string => __('Resumen')),
                NavigationGroup::make(fn (): string => __('Socios')),
                NavigationGroup::make(fn (): string => __('Dispensario')),
                NavigationGroup::make(fn (): string => __('Barra y tienda')),
                NavigationGroup::make(fn (): string => __('Caja')),
                // Informes (reports) — one page per report, each permission-gated
                // (reports.view; the assembly pack needs reports.view.all). Discovered
                // from App\Filament\Pages\Reports; the group renders only when the actor
                // can see at least one report, so STAFF (no reports.view) never sees it.
                NavigationGroup::make(fn (): string => __('Informes')),
                // Documentos (prompt 16): the legal-documents module — libro de socios,
                // actas, plantillas, registro de dispensación, exportación contable and the
                // generated-documents vault. Each item is permission-gated (register.view /
                // minutes.manage / documents.generate / reports.view / reports.export /
                // member.documents.view), so the group renders only when the actor can see
                // at least one — STAFF never sees it.
                NavigationGroup::make(fn (): string => __('Documentos')),
                // Club communications (prompt 15): announcements + events, surfaced in the
                // member PWA. Renders only when the actor holds comms.manage.
                NavigationGroup::make(fn (): string => __('Comunicaciones')),
                NavigationGroup::make(fn (): string => __('Sistema')),
            ])
            // ONE link into the counter (prompt 172). There were FOUR, scattered across four different
            // navigation groups — Acceso/Check-in under Socios, TPV dispensario under Dispensario, TPV
            // barra under Barra y tienda, Terminal de caja under Caja — which presented one application
            // as four tools reached from four places, and put the counter's navigation in two places that
            // had to agree. The fifth destination, Socios (counter.members), never had a link at all:
            // four of five is not a policy, it is drift.
            //
            // The counter is one application with its own permission-filtered tab strip. Once inside, that
            // strip is the navigation. So this is a front door, not a member of any operational group —
            // it sits in Resumen, which is where "where do I go" belongs.
            //
            // Gate and destination both come from App\Support\CounterScreens, the same list the tab strip
            // reads: the link renders when the user can reach AT LEAST ONE counter screen, and lands on
            // Recepción when they can be there or on the first screen they are allowed to open otherwise.
            // A user with till.open and not checkin.manage therefore lands on Caja, not on a 403.
            ->navigationItems([
                NavigationItem::make(fn (): string => __('Mostrador'))
                    ->url(fn (): string => route(CounterScreens::landingRouteFor(Auth::user()) ?? 'counter.checkin'))
                    ->icon(Heroicon::OutlinedComputerDesktop)
                    ->group(fn (): string => __('Resumen'))
                    ->sort(1)
                    ->visible(fn (): bool => CounterScreens::reachableByAny(Auth::user())),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            // App\Filament\Pages\Dashboard (our custom home) is discovered here and mounted
            // at "/" — Filament's stock dashboard is intentionally NOT registered, and its
            // account/version/docs furniture is gone: the club's data or nothing.
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
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
