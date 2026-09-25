<?php

use App\Http\Middleware\EnforceCounterHandover;
use App\Http\Middleware\EnforceOrgLockdown;
use App\Http\Middleware\RequireOpenTill;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Predis\PredisException;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpFoundation\Response;

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

        // Org-wide panic lockdown (prompt 121): appended globally so it gates the panel, the counter and the
        // member PWA at once. When the org is locked it returns an ordinary "temporarily unavailable" page.
        // Stays GLOBAL deliberately: it reads the DATABASE (OrganisationLockdown::active), never the session,
        // so it works before StartSession — unlike the two session-reading guards below (prompt 241).
        $middleware->append(EnforceOrgLockdown::class);

        // Prompt 241 — the counter's two session-reading guards run on the WEB GROUP, after StartSession, NOT
        // the GLOBAL stack. Global middleware run OUTSIDE the web group and therefore BEFORE StartSession, so
        // `session()` is not started yet: their reads returned null on every real request and both silently
        // degraded open. `EnforceCounterHandover` reads `CounterHandover::active()` → session, and
        // `RequireOpenTill` reads `counter.location_id` + `CounterOperator::id()` → session. Registered here,
        // after StartSession, they see the real session. Order is load-bearing: handover before till (a
        // handover is the applicant holding the tablet; the till step is the operator's), then SetLocale.
        //
        // `EnforceCounterHandover` ALSO gates the Filament panel, whose `/` served the applicant the dashboard
        // before 209. The panel runs its OWN middleware stack (not the web group), so the guard is added there
        // too, after that stack's StartSession — see AdminPanelProvider. One boundary, both surfaces, each with
        // a started session behind it.
        $middleware->web(append: [
            EnforceCounterHandover::class,
            RequireOpenTill::class,
            SetLocale::class,
        ]);

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

        // A Redis outage must SAY something true, not a stack-trace 500 or a silent bounce (prompt 124). With
        // the permission cache on the database store, authenticated screens (the counter included) render fine;
        // the paths that still touch Redis — the login throttle, an explicit cache/queue call — now surface a
        // maintenance message instead. Scoped to Redis by exception type / the :6379 in its message, so a DB
        // failure (a different, total outage) is untouched.
        $exceptions->render(function (Throwable $e, Request $request): ?Response {
            $isRedis = false;
            for ($cursor = $e, $i = 0; $cursor !== null && $i < 6; $cursor = $cursor->getPrevious(), $i++) {
                if ($cursor instanceof PredisException
                    || (class_exists('RedisException', false) && $cursor instanceof RedisException)
                    || str_contains($cursor->getMessage(), '6379')) {
                    $isRedis = true;
                    break;
                }
            }

            if (! $isRedis) {
                return null;
            }

            $message = __('El sistema no está disponible temporalmente (infraestructura degradada). Inténtalo de nuevo en unos momentos.');

            return ($request->expectsJson() || $request->is('api/*'))
                ? response()->json(['message' => $message], 503)
                : response()->view('errors.degraded', ['message' => $message], 503);
        });
    })->create();
