<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Livewire\Counter\BarPos;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 198 — locking the counter must not cost the sale.
 *
 * Prompt 120's design is that locking PRESERVES state: it signs the operator out server-side and leaves the
 * basket alone, so unlocking resumes exactly where it left off. That is still true of the MECHANISM —
 * `SurfaceModeReactivityTest::test_a_basket_in_progress_survives_every_transition` proves it — and it was the
 * ROUTE to the mechanism that destroyed the thing the mechanism promised to keep.
 *
 * Prompt 189 moved the lock to the counter home with the other non-transaction operations, which was right
 * for the top bar and is what that prompt asked for. But locking is not like switching sede or opening the
 * panel: it is what you do while standing at the counter with a member in front of you. With the only control
 * on `/counter`, reaching it crossed prompt 196's unsaved-work confirm — correct, newly working, and exactly
 * what made it expensive — so the operator's real choice mid-order was to leave the terminal unlocked or
 * abandon the basket.
 *
 * The idle timer, firing in place, always kept the basket. The DELIBERATE control was the one that lost it.
 */
class LockInPlaceTest extends TestCase
{
    use RefreshDatabase;

    /** Every screen that carries the counter chrome — the lock must be reachable from all of them. */
    private const COUNTER_ROUTES = [
        'counter.home', 'counter.checkin', 'counter.members', 'counter.pos', 'counter.bar', 'counter.till',
    ];

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id, 'capacity' => 20]);
    }

    private function operator(): User
    {
        $user = User::factory()->create(['pin' => bcrypt('4321')]);
        $user->assignRole(Role::OWNER->value);
        $user->locations()->sync([$this->location->id]);
        CounterOperator::set($user);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);

        return $user;
    }

    /**
     * The reported bug, as a guard: an in-place lock on every counter screen.
     *
     * 198 put it in the bar's overflow MENU (one tap to open, one to press). Prompt 205 removed the overflow
     * and made it a first-class control on the row — `[data-counter-lock]`, ONE tap — which is the strongest
     * form of what 198 asked for. The hook changed; the guarantee is unchanged and is asserted harder.
     */
    public function test_every_counter_screen_offers_a_lock_that_does_not_leave_the_screen(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        foreach (self::COUNTER_ROUTES as $route) {
            $html = $this->get(route($route))->assertOk()->getContent();

            $this->assertStringContainsString(
                'data-counter-lock',
                (string) $html,
                $route.' must offer a lock without navigating away — locking is a mid-transaction action.',
            );
        }
    }

    /**
     * And it must not be behind the unsaved-work confirm, which is the whole defect.
     *
     * The confirm itself is correct and stays on the controls that genuinely leave the counter; what was
     * wrong is that it stood between the operator and a control designed to PRESERVE their work.
     */
    public function test_the_in_place_lock_is_not_behind_the_unsaved_work_confirm(): void
    {
        $this->operator();
        $html = (string) $this->get(route('counter.bar'))->assertOk()->getContent();

        $start = strpos($html, 'data-counter-lock');
        $this->assertNotFalse($start, 'the in-place lock must exist');

        // The control's own markup, up to the end of its element.
        $control = substr($html, $start, (int) strpos($html, '</button>', $start) - $start);

        $this->assertStringContainsString('lockNow()', $control, 'it calls the store, which does not navigate');
        $this->assertStringNotContainsString('dirty', $control, 'locking must never ask whether work would be lost');
        $this->assertStringNotContainsString('confirm(', $control);
    }

    /**
     * The guard is still on the controls that DO leave the counter — 196's behaviour, unchanged.
     *
     * **Home dropped off this list in prompt 206**, and that is the point rather than an omission: the basket
     * is session-backed, so a trip to the hub cannot lose it, and this test asserting `dirty` on the Home link
     * is what the previous behaviour looked like from the inside. Home's own guard is `volatile`, asserted in
     * `TopBarNamesItsDestinationsTest`.
     */
    public function test_the_unsaved_work_guard_still_fires_on_the_routes_that_leave_the_counter(): void
    {
        $this->operator();
        $html = (string) $this->get(route('counter.bar'))->assertOk()->getContent();

        foreach (['data-counter-admin-link', 'data-counter-logout'] as $marker) {
            $at = strpos($html, $marker);
            $this->assertNotFalse($at, $marker.' must be present');

            // Each of these sits within a few hundred characters of its own guard expression.
            $this->assertStringContainsString(
                'dirty',
                substr($html, max(0, $at - 400), 900),
                $marker.' leaves the counter and must still confirm when work is in progress',
            );
        }
    }

    /**
     * The guarantee prompt 120 made, through the control this branch adds: lock, unlock, basket intact.
     *
     * `lockCounter()` is what the manual control dispatches (`counter-lock`) and what the idle timer
     * dispatches — one mechanism, which is why the idle path never had this bug.
     */
    public function test_a_basket_in_progress_survives_a_manual_lock_and_unlock(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'BAR-1', 10000);

        $basket = [['type' => 'misc', 'description' => 'Propina', 'unit_price_cents' => 200, 'qty' => 1, 'reference' => '']];

        Livewire::test(BarPos::class)
            ->set('basket', $basket)
            ->call('lockCounter')
            ->assertSet('basket', $basket)
            ->set('operatorPin', '4321')
            ->call('unlockOperator')
            ->assertSet('basket', $basket);
    }

    /** Locking signs the operator out SERVER-SIDE — a refused commit, not merely an overlay. */
    public function test_the_manual_lock_refuses_a_commit_rather_than_only_covering_the_screen(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'BAR-1', 10000);

        $basket = [['type' => 'misc', 'description' => 'Propina', 'unit_price_cents' => 200, 'qty' => 1, 'reference' => '']];

        Livewire::test(BarPos::class)
            ->set('basket', $basket)
            ->call('lockCounter')
            ->call('commitOrder')
            ->assertSet('basket', $basket); // the work is kept…

        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count(), '…and nothing was written');
    }
}
