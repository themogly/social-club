<?php

namespace Tests\Feature\Middleware;

use App\Http\Middleware\EnforceCounterHandover;
use App\Http\Middleware\RequireOpenTill;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Two probe middlewares that record whether the session was STARTED at their point in the pipeline. */
class ProbeGlobalSessionMw
{
    public static bool $started = false;

    public function handle(Request $request, Closure $next)
    {
        self::$started = app('session.store')->isStarted();

        return $next($request);
    }
}
class ProbeWebSessionMw
{
    public static bool $started = false;

    public function handle(Request $request, Closure $next)
    {
        self::$started = app('session.store')->isStarted();

        return $next($request);
    }
}

/**
 * Prompt 241 — a middleware that reads the session must run AFTER StartSession, which means the WEB group (or
 * a panel's own stack), never the GLOBAL stack.
 *
 * 236 shipped `RequireOpenTill` on the global stack and green. It never fired on a real request: the global
 * stack runs OUTSIDE the route middleware groups, so BEFORE the web group's StartSession — at which point
 * `session()` is not started and every read returns null, taking the guard's degrade-open path (prompt 124) on
 * EVERY request. The tests passed because they seed the session in-process (`session([...])`/`withSession()`),
 * which populates the in-memory store the guard also reads — the two shared one object, so the ordering never
 * showed. The in-process-session false-green is now in the catalogue.
 *
 * These are the deterministic guards for the ordering. The real-lifecycle REDIRECT is proven by the browser
 * harness (`tests/Browser/shoot-till-guard.mjs`) against a running server, because Laravel's test client
 * abstracts the session (that abstraction is the very false-green above) and cannot reproduce a fresh
 * per-request store in-process.
 */
class CounterGuardsRunAfterSessionTest extends TestCase
{
    // --- The mechanism, shown ----------------------------------------------------

    public function test_the_global_stack_runs_before_the_session_is_started(): void
    {
        ProbeGlobalSessionMw::$started = false;
        ProbeWebSessionMw::$started = false;

        app(Kernel::class)->pushMiddleware(ProbeGlobalSessionMw::class);        // like $middleware->append()
        app(Router::class)->pushMiddlewareToGroup('web', ProbeWebSessionMw::class); // like the SetLocale precedent

        Route::middleware('web')->get('/__ordering', fn (): string => 'ok');
        $this->get('/__ordering')->assertOk();

        // This is WHY a session-reading guard cannot be global: at the global point the session is not started,
        // so session() reads null. On the web group, after StartSession, it is started.
        $this->assertFalse(ProbeGlobalSessionMw::$started, 'the session was started at a GLOBAL middleware — the defect would not exist');
        $this->assertTrue(ProbeWebSessionMw::$started, 'the session was NOT started at a web-group middleware');
    }

    // --- The class guard, over bootstrap/app.php + AdminPanelProvider ------------

    /** Middleware registered on the GLOBAL stack (`append`/`prepend`) whose SOURCE reads the session. */
    private function globalMiddlewareReadingSession(string $bootstrap): array
    {
        $imports = [];
        foreach (explode("\n", $bootstrap) as $line) {
            if (preg_match('/^use\s+([\\\\A-Za-z0-9_]+\\\\([A-Za-z0-9_]+));/', trim($line), $m)) {
                $imports[$m[2]] = ltrim($m[1], '\\');
            }
        }

        $offenders = [];
        foreach (explode("\n", $bootstrap) as $i => $line) {
            // GLOBAL registration only: `$middleware->append(...)` / `->prepend(...)`. NOT `->web(append: …)`.
            if (! preg_match('/\$middleware->(?:append|prepend)\(/', $line)) {
                continue;
            }
            foreach ($this->classesIn($line) as $short) {
                $fqcn = $imports[$short] ?? null;
                if ($fqcn === null) {
                    continue;
                }
                $file = base_path(str_replace('\\', '/', $fqcn).'.php');
                $file = str_replace('App/', 'app/', $file);
                if (! is_file($file)) {
                    continue;
                }
                $src = (string) file_get_contents($file);
                if (preg_match('/session\(|->session\(\)|CounterOperator::/', $src)) {
                    $offenders[] = "{$short} (global, bootstrap/app.php line ".($i + 1).') reads the session — register it on the web group AFTER StartSession (the SetLocale precedent), never the global stack';
                }
            }
        }

        return $offenders;
    }

    /** Every `Foo::class` mentioned on a line. */
    private function classesIn(string $line): array
    {
        preg_match_all('/([A-Za-z0-9_]+)::class/', $line, $m);

        return $m[1] ?? [];
    }

    public function test_no_session_reading_middleware_is_registered_globally(): void
    {
        $offenders = $this->globalMiddlewareReadingSession((string) file_get_contents(base_path('bootstrap/app.php')));

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    /** The plant (prompt 241): the guard MUST flag the exact defect 236 shipped, or it guards nothing. */
    public function test_the_guard_catches_a_planted_global_session_reader(): void
    {
        $planted = <<<'PHP'
        <?php
        use App\Http\Middleware\RequireOpenTill;
        return Application::configure()->withMiddleware(function ($middleware): void {
            $middleware->append(RequireOpenTill::class);
        });
        PHP;

        $offenders = $this->globalMiddlewareReadingSession($planted);

        $this->assertNotEmpty($offenders, 'the guard did not catch a global session-reading middleware');
        $this->assertStringContainsString('RequireOpenTill', $offenders[0]);
        $this->assertStringContainsString('web group', $offenders[0]);
    }

    // --- The registration, positively -------------------------------------------

    public function test_the_two_counter_guards_run_on_the_web_group(): void
    {
        $web = app(Router::class)->getMiddlewareGroups()['web'];
        $start = array_search(StartSession::class, $web, true);

        foreach ([EnforceCounterHandover::class, RequireOpenTill::class] as $guard) {
            $at = array_search($guard, $web, true);
            $this->assertNotFalse($at, $guard.' must run on the web group');
            $this->assertGreaterThan($start, $at, $guard.' must run AFTER StartSession');
        }
    }

    public function test_the_handover_gate_also_runs_on_the_panel_stack_after_its_session(): void
    {
        $panel = Filament::getPanel('admin');
        $stack = $panel->getMiddleware();

        $start = array_search(StartSession::class, $stack, true);
        $at = array_search(EnforceCounterHandover::class, $stack, true);

        $this->assertNotFalse($start, 'the panel has no StartSession — unexpected');
        $this->assertNotFalse($at, 'the handover gate must be on the panel stack (209 — GET / is a panel route)');
        $this->assertGreaterThan($start, $at, 'the handover gate must run AFTER the panel StartSession');
    }
}
