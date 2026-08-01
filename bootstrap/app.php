<?php

use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Developer-only routes (gated to local by middleware inside the file).
            Route::middleware('web')->group(__DIR__.'/../routes/dev.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a reverse proxy / load balancer (the normal deployment), honour X-Forwarded-* so
        // isSecure() is true on HTTPS — otherwise the panel and POS emit http:// asset URLs on an https
        // page and the browser blocks them as mixed content, making the app unusable (prompt 78). Trust the
        // proxy set from TRUSTED_PROXIES (default '*' — tighten to the load balancer's addresses in prod).
        $proxies = (string) env('TRUSTED_PROXIES', '*');
        $middleware->trustProxies(at: $proxies === '*' ? '*' : explode(',', $proxies));

        // Baseline security + no-index headers on every response (all surfaces,
        // including the Filament panel which uses its own middleware stack).
        $middleware->append(SecurityHeaders::class);

        // Apply the session locale on web routes (after StartSession). The panel
        // adds it to its own stack in AdminPanelProvider.
        $middleware->web(append: [SetLocale::class]);

        // Guests are sent to the guard's OWN login: members to the PWA login, staff to
        // the Filament panel (which handles its own auth). The two guards never cross.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('socio*') ? route('socio.login') : '/'
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Report unhandled exceptions to Sentry (inert until SENTRY_LARAVEL_DSN is set).
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
