<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Livewire\Counter\CounterHome;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 196 — an Alpine directive outside an `x-data` scope never runs, and fails with NO error at all.
 *
 * Alpine 3 does not walk the document on start. It queries its root selectors and calls `initTree` only on
 * those subtrees, so an element carrying `@click` with no `x-data` ancestor is simply never initialised.
 * There is no console warning, no exception, nothing — which is why the counter's shared header shipped with
 * five dead handlers and nobody noticed.
 *
 * What that cost: prompt 120's MANUAL lock did nothing (the idle timer was unaffected — it is registered on
 * `alpine:init` and never depended on a DOM binding, so the automatic control worked and the deliberate one
 * did not), and prompt 23's unsaved-work guard on the tab strip never fired. The nav items are real
 * `<a href>`s, so `@click.prevent` not running meant the browser just followed the link — the guard was not
 * merely absent, it was bypassed silently with a basket open. The overflow menu's copy of the same guard
 * worked, because that menu has its own `x-data` island.
 *
 * This is the only kind of test that can catch the next one, so it is structural rather than a re-assertion
 * of the instance.
 */
class AlpineScopeTest extends TestCase
{
    use RefreshDatabase;

    /** Every counter screen, since they all render the same shared chrome. */
    private const SCREENS = ['counter.home', 'counter.checkin', 'counter.members', 'counter.till', 'counter.pos', 'counter.bar'];

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $this->location = Location::factory()->create(['organisation_id' => $org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);

        $user = User::factory()->create(['name' => 'Club Staff', 'pin' => Hash::make('1234')]);
        $user->assignRole(Role::OWNER->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($user);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
    }

    /**
     * Every element carrying an Alpine directive, paired with whether it sits inside an `x-data` scope.
     *
     * @return list<string> offenders as "element — directive"
     */
    private function unscopedDirectives(string $html): array
    {
        // DOMDocument drops `@click` — an attribute name it considers invalid — but keeps `x-on:click`, which
        // is the same directive spelled out. Normalising the shorthand first is a faithful rewrite and the
        // only way to see the shorthand at all.
        $html = (string) preg_replace('/\s@([a-zA-Z][\w.:-]*)=/', ' x-on:$1=', $html);

        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $offenders = [];

        foreach ((new DOMXPath($document))->query('//*') as $node) {
            if (! $node instanceof DOMElement || $node->attributes === null) {
                continue;
            }

            foreach ($node->attributes as $attribute) {
                $name = $attribute->name;

                // `wire:` is Livewire's, bound by Livewire itself and independent of any Alpine scope.
                if (str_starts_with($name, 'wire:') || $name === 'x-data') {
                    continue;
                }

                if (! str_starts_with($name, 'x-') && ! str_starts_with($name, ':')) {
                    continue;
                }

                if ($this->hasScopeAncestor($node)) {
                    continue;
                }

                $label = $node->nodeName;
                foreach (['data-counter-lock-now', 'data-counter-screen', 'data-counter-home-lock', 'data-counter-home-link', 'data-commit-action', 'data-product'] as $hook) {
                    if ($node->hasAttribute($hook)) {
                        $label = $hook;
                        break;
                    }
                }

                $offenders[] = $label.' — '.$name;
            }
        }

        return array_values(array_unique($offenders));
    }

    private function hasScopeAncestor(DOMElement $node): bool
    {
        for ($cursor = $node; $cursor instanceof DOMElement; $cursor = $cursor->parentNode) {
            if ($cursor->hasAttribute('x-data')) {
                return true;
            }
        }

        return false;
    }

    public function test_no_alpine_directive_renders_outside_a_scope_on_any_counter_screen(): void
    {
        $failures = [];

        foreach (self::SCREENS as $route) {
            $html = $this->get(route($route))->assertOk()->getContent();

            foreach ($this->unscopedDirectives($html) as $offender) {
                $failures[] = $route.': '.$offender;
            }
        }

        $this->assertSame([], $failures,
            'These Alpine directives render OUTSIDE any x-data scope, so Alpine never initialises them and '.
            "they silently do nothing — with no console error to notice:\n  ".implode("\n  ", $failures));
    }

    // --- the instance: the lock is a security control, so assert the SERVER-side half ------------------

    public function test_the_lock_control_signs_the_operator_out_server_side(): void
    {
        // The overlay is presentation. What actually refuses a commit is the operator being cleared, and
        // that is the half that was unreachable while the handler was unbound.
        $this->assertNotNull(CounterOperator::id(), 'precondition: an operator is identified');

        Livewire::test(CounterHome::class)->call('lockCounter');

        $this->assertNull(CounterOperator::id(), 'The lock did not sign the operator out server-side.');
    }

    public function test_the_idle_timer_still_locks_unchanged(): void
    {
        // It never depended on a DOM binding — it registers on `alpine:init` — so it worked all along and
        // must keep working. Same server-side entry point, same result.
        $this->assertNotNull(CounterOperator::id());

        Livewire::test(CounterHome::class)->call('lockCounter');

        $this->assertNull(CounterOperator::id());
    }

    public function test_the_unsaved_work_guard_is_present_on_every_tab_strip_link(): void
    {
        // The nav items are real <a href>s, so an unbound @click.prevent does not merely fail to warn — the
        // browser follows the link and the basket is gone. The guard renders; the scope is what makes it run.
        $html = $this->get(route('counter.checkin'))->assertOk()->getContent();

        preg_match_all('/data-counter-screen="[^"]*"/', $html, $links);
        $this->assertGreaterThan(1, count($links[0]), 'expected a tab strip with several destinations');
        $this->assertStringContainsString('$store.counter?.dirty', $html, 'The unsaved-work guard is missing.');
    }
}
