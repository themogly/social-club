<?php

namespace Tests\Feature\Middleware;

use App\Http\Middleware\EnforceCounterHandover;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Component;
use ReflectionClass;
use Tests\TestCase;

/**
 * Prompt 249 — the counter-layout allowlist cannot lose a screen.
 *
 * The bug this guards: 189's front door (`/counter`) was added AFTER the first five counter screens were
 * allowlisted by hand in {@see EnforceCounterHandover}, and nobody added it — so during a handover it
 * redirected instead of rendering its (safe) surface, and the tablet had no front door. This enumerates every
 * route whose action is a Livewire component carrying `#[Layout('components.layouts.counter')]` FROM THE ROUTE
 * TABLE (never a hand list) and asserts each one answers during a handover. A seventh screen added tomorrow is
 * caught the moment it is routed.
 */
class CounterLayoutRoutesAreAllowedTest extends TestCase
{
    /**
     * Every GET route whose action is a Livewire component using the counter layout, mapped uri => class.
     *
     * @return array<string, class-string>
     */
    private function counterLayoutRoutes(): array
    {
        $found = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            // Livewire full-page routes expose the component as the controller; real controllers carry "@method".
            $controller = $route->getAction('controller');
            if (! is_string($controller) || $controller === '' || str_contains($controller, '@')) {
                continue;
            }

            $class = ltrim($controller, '\\');
            if (! class_exists($class) || ! is_subclass_of($class, Component::class)) {
                continue;
            }

            if ($this->usesCounterLayout($class)) {
                $found[$route->uri()] = $class;
            }
        }

        return $found;
    }

    private function usesCounterLayout(string $class): bool
    {
        foreach ((new ReflectionClass($class))->getAttributes(Layout::class) as $attribute) {
            if (($attribute->getArguments()[0] ?? null) === 'components.layouts.counter') {
                return true;
            }
        }

        return false;
    }

    public function test_every_counter_layout_route_answers_during_a_handover(): void
    {
        $routes = $this->counterLayoutRoutes();

        // If the enumeration finds nothing, it is broken — better a loud failure than a green no-op.
        $this->assertNotEmpty($routes, 'no counter-layout routes were enumerated — the reflection is broken');
        // Sanity: the six known screens are all here (checkin/members/till/pos/bar + the front door).
        $this->assertGreaterThanOrEqual(6, count($routes), 'fewer counter-layout routes than the six known screens');

        $uncovered = array_keys(array_filter(
            $routes,
            fn (string $class, string $uri): bool => ! EnforceCounterHandover::allows($uri),
            ARRAY_FILTER_USE_BOTH,
        ));

        $this->assertSame([], $uncovered,
            'counter-layout routes missing from the handover allowlist: '.implode(', ', $uncovered));
    }

    public function test_the_guard_would_catch_a_forgotten_screen(): void
    {
        // Plant a counter-layout route that is NOT in the allowlist, and prove the enumeration flags it — so a
        // green run of the test above means "every screen is covered", not "the check does nothing".
        Route::get('counter/__planted', PlantedCounterScreen::class);

        $routes = $this->counterLayoutRoutes();

        $this->assertArrayHasKey('counter/__planted', $routes, 'the enumeration missed a planted counter screen');
        $this->assertFalse(EnforceCounterHandover::allows('counter/__planted'), 'the allowlist already covers the plant');

        $uncovered = array_keys(array_filter(
            $routes,
            fn (string $class, string $uri): bool => ! EnforceCounterHandover::allows($uri),
            ARRAY_FILTER_USE_BOTH,
        ));

        $this->assertContains('counter/__planted', $uncovered, 'the guard did not flag the planted violation');
    }
}

/** A throwaway counter-layout screen, used only to prove the enumeration flags an un-allowlisted route. */
#[Layout('components.layouts.counter')]
class PlantedCounterScreen extends Component
{
    public function render(): string
    {
        return '<div>planted</div>';
    }
}
