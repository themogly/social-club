<?php

namespace Tests\Feature\Counter;

use App\Enums\Role;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\CounterChrome;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Article;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterHandover;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 209 — the chrome came back with the counter, because the layout is outside Livewire.
 *
 * Reported: *"managed to lose the top bar when I went to sign up a new member, clicked hand tablet over and
 * clicked back."* Reproduced on `988a845`: hand over → Back → *Personal del club* → PIN, and the counter is
 * back, the operator is restored, the green *"Trabajando: …"* confirmation is on screen — **and there is no
 * top bar.** No sede, no lock, no way to any other screen. Since 205 the bar is the only navigation, so the
 * terminal was stranded until somebody reloaded.
 *
 * The server logic was right the whole time. `components/layouts/counter.blade.php` asked
 * `CounterHandover::active()` whether the chrome should exist — the correct RULE (173: absent from the DOM,
 * not hidden) in the wrong PLACE. `unlockOperator()` ends the handover inside a **Livewire action**, and a
 * Livewire response replaces the component's markup and nothing else, so the layout's branch stayed frozen at
 * whatever it decided on the previous full page load.
 *
 * **Prompt 188's failure one level out**: 188 was Alpine snapshotting server state into `x-data`; this was the
 * layout snapshotting it into the DOM.
 */
class ChromeReturnsWithTheCounterTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
    }

    private function operator(Role $role = Role::OWNER): User
    {
        $user = User::factory()->create(['pin' => Hash::make('4321')]);
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($user);

        return $user;
    }

    /** Hand the tablet over exactly as the screen does — through the component, never by setting the session. */
    private function handOver(): void
    {
        Livewire::test(MembershipCounter::class)->call('toggleAlta')->call('handOverForAlta');
        $this->assertTrue(CounterHandover::active(), 'precondition: the tablet was not handed over');
    }

    // --- The reported bug --------------------------------------------------------------------

    /**
     * Recovering from a handover restores the top bar **with no page reload**.
     *
     * Modelled as what actually happens: the chrome is on screen from the previous full page load, the
     * operator recovers through a Livewire ACTION on the page component, and the chrome answers the event
     * that action dispatches. No `$this->get()` anywhere between — a GET would prove nothing, because a
     * reload has always fixed this.
     */
    public function test_the_top_bar_comes_back_after_a_handover_without_a_page_reload(): void
    {
        $operator = $this->operator();
        $this->handOver();

        // Step 3 of the report: Back to the counter. The chrome is absent, which is 173 working.
        $chrome = Livewire::test(CounterChrome::class);
        $chrome->assertDontSee('data-counter-topbar', false);

        // Step 4: "Personal del club" → PIN. A Livewire action; the page never reloads.
        CounterOperator::clear();
        Livewire::test(MembershipCounter::class)
            ->set('operatorPin', '4321')
            ->call('unlockOperator')
            ->assertDispatched('counter-unlocked');

        $this->assertFalse(CounterHandover::active(), 'the handover did not end');
        $this->assertSame($operator->id, CounterOperator::id(), 'the operator was not restored');

        // …and the SAME chrome, answering that event, has its bar back. This is the assertion that fails
        // against `main`, where the decision lived in a layout no Livewire response re-renders.
        $chrome->dispatch('counter-unlocked')->assertSee('data-counter-topbar', false);
    }

    /** Everything the bar carries comes back, not just the element that holds it. */
    public function test_every_control_the_bar_carries_comes_back(): void
    {
        $this->operator();
        $this->handOver();
        $chrome = Livewire::test(CounterChrome::class);

        CounterOperator::clear();
        Livewire::test(MembershipCounter::class)->set('operatorPin', '4321')->call('unlockOperator');

        $html = $chrome->dispatch('counter-unlocked')->html();

        foreach ([
            'data-counter-home-link', 'data-counter-sede-region', 'data-counter-lock',
            'data-counter-admin-link', 'data-counter-logout', 'data-counter-panic',
        ] as $hook) {
            $this->assertStringContainsString($hook, $html, "{$hook} did not come back");
        }
    }

    /**
     * The idle path has the identical shape and the identical fix.
     *
     * `lockCounter()` also ends a handover — an abandoned tablet must land on the lock screen, not return a
     * live till — and it runs in a Livewire action too. So a timed-out handover then a PIN must also bring
     * the chrome back.
     */
    public function test_a_timed_out_handover_then_a_pin_brings_the_chrome_back(): void
    {
        $this->operator();
        $this->handOver();
        $chrome = Livewire::test(CounterChrome::class);
        $chrome->assertDontSee('data-counter-topbar', false);

        // The idle timer fires: Alpine dispatches `counter-lock`, the page component ends the handover.
        Livewire::test(MembershipCounter::class)->dispatch('counter-lock');
        $this->assertFalse(CounterHandover::active(), 'the timeout did not end the handover');
        $this->assertNull(CounterOperator::id(), 'the timeout did not sign the operator out');

        Livewire::test(MembershipCounter::class)->set('operatorPin', '4321')->call('unlockOperator');

        $chrome->dispatch('counter-unlocked')->assertSee('data-counter-topbar', false);
    }

    // --- 173's guarantee, unchanged --------------------------------------------------------

    /**
     * While handed over, the chrome is ABSENT FROM THE DOM on every counter screen — not hidden by CSS.
     *
     * Asserted against the raw response body, hook by hook, because "hidden" and "absent" look the same in a
     * rendered-text assertion and only one of them is 173's guarantee.
     */
    public function test_the_chrome_is_absent_from_the_dom_on_every_screen_while_handed_over(): void
    {
        $this->operator();
        $this->handOver();

        foreach (['counter.checkin', 'counter.members', 'counter.till', 'counter.pos', 'counter.bar'] as $route) {
            $html = (string) $this->get(route($route))->assertOk()->getContent();

            foreach ([
                'data-counter-topbar', 'data-counter-home-link', 'data-counter-sede-region',
                'data-counter-lock', 'data-counter-admin-link', 'data-counter-logout',
                'data-counter-panic', 'data-counter-home-tile', 'counter-main">',
            ] as $hook) {
                if ($hook === 'counter-main">') {
                    continue;   // <main> is the page, not the chrome
                }
                $this->assertStringNotContainsString($hook, $html, "{$route} leaked {$hook} during a handover");
            }

            $this->assertStringNotContainsString(__('Saltar al contenido'), $html, "{$route} leaked the skip link");
        }
    }

    /** Back to the counter during a handover still shows the surface and its way back, never the screen. */
    public function test_the_back_button_during_a_handover_shows_the_surface_with_a_way_back(): void
    {
        $this->operator();
        $this->handOver();

        $html = (string) $this->get(route('counter.members'))->assertOk()->getContent();

        $this->assertStringContainsString('data-counter-surface', $html, 'the surface is not up');
        $this->assertStringContainsString('data-handover-staff', $html, '187\'s way back is missing');
        $this->assertStringContainsString(__('Personal del club'), $html);
        // …and none of the working screen behind it.
        $this->assertStringNotContainsString('data-member-lookup', $html, 'the working screen rendered during a handover');
    }

    // --- Nothing was traded away to get the bar back ---------------------------------------

    /** A basket in progress survives the recovery — 205's guarantee, and the reason a redirect was refused. */
    public function test_a_basket_survives_the_handover_recovery(): void
    {
        $this->operator();
        $article = Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'price_cents' => 120, 'stock' => 10, 'active' => true,
        ]);

        Livewire::test(BarPos::class)->call('addArticle', $article->id);
        $this->handOver();

        CounterOperator::clear();
        Livewire::test(MembershipCounter::class)->set('operatorPin', '4321')->call('unlockOperator');

        $this->assertCount(1, Livewire::test(BarPos::class)->get('basket'), 'the basket did not survive the recovery');
    }

    /** And form state on the screen underneath is not thrown away either. */
    public function test_form_state_on_the_screen_underneath_survives_the_recovery(): void
    {
        $this->operator();
        $this->handOver();

        CounterOperator::clear();
        $screen = Livewire::test(MembershipCounter::class)
            ->set('feeAmount', '12,50')
            ->set('operatorPin', '4321')
            ->call('unlockOperator');

        $screen->assertSet('feeAmount', '12,50');
    }
}
