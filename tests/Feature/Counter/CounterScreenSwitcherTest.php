<?php

namespace Tests\Feature\Counter;

use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 42 — the shared counter top-bar carries a screen switcher: permission-filtered per
 * the real per-screen gate, the current screen marked active (not a link), and every switch
 * link guarded by the same unsaved-work confirm as the existing Panel link.
 */
class CounterScreenSwitcherTest extends TestCase
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
    }

    /** @param  list<string>  $permissions */
    private function operator(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        CounterOperator::set($user);
        app(ActiveScope::class)->setLocation($this->location->id);

        return $user;
    }

    public function test_a_full_access_operator_sees_all_four_screens_with_the_current_one_active(): void
    {
        $this->operator(['checkin.manage', 'pos.use', 'pos.bar', 'till.open', 'till.close']);

        $response = $this->get(route('counter.checkin'));

        $response->assertOk()
            ->assertSee(__('Recepción'))
            ->assertSee(__('Dispensario'))
            ->assertSee(__('Barra'))
            ->assertSee(__('Caja'))
            ->assertSee('aria-current="page"', false);

        // The current screen (check-in) is the active marker, NOT a switch link; the other three ARE.
        $response->assertDontSee('data-counter-switch="counter.checkin"', false)
            ->assertSee('data-counter-switch="counter.pos"', false)
            ->assertSee('data-counter-switch="counter.bar"', false)
            ->assertSee('data-counter-switch="counter.till"', false);
    }

    public function test_a_single_permission_operator_only_sees_their_own_screen(): void
    {
        // pos.bar only — no checkin.manage / pos.use / till.*
        $this->operator(['pos.bar']);

        $response = $this->get(route('counter.bar'));

        // Only the bar screen is in the switcher, and it's the active one (no switch links at all).
        $response->assertOk()
            ->assertSee('data-counter-screen="counter.bar"', false)
            ->assertDontSee('data-counter-switch', false)
            // Never a switcher entry for a screen this user would 403 on.
            ->assertDontSee('data-counter-screen="counter.checkin"', false)
            ->assertDontSee('data-counter-screen="counter.pos"', false)
            ->assertDontSee('data-counter-screen="counter.till"', false);
    }

    public function test_every_switch_link_carries_the_unsaved_work_confirm_guard(): void
    {
        $this->operator(['checkin.manage', 'pos.use', 'pos.bar', 'till.open', 'till.close']);

        $html = $this->get(route('counter.checkin'))->getContent();

        // Switch links exist and reuse the SAME store/confirm pattern as the Panel link.
        $this->assertStringContainsString('data-counter-switch="counter.bar"', $html);
        $this->assertStringContainsString('$store.counter?.dirty', $html);
        $this->assertStringContainsString("window.location.assign('".route('counter.bar')."')", $html);
    }

    public function test_the_active_screen_is_marked_on_each_screen(): void
    {
        $this->operator(['checkin.manage', 'pos.use', 'pos.bar', 'till.open', 'till.close']);

        // On the till screen, Caja is active (not a switch link) and the others are switch links.
        $response = $this->get(route('counter.till'));

        $response->assertOk()
            ->assertSee('aria-current="page"', false)
            ->assertDontSee('data-counter-switch="counter.till"', false)
            ->assertSee('data-counter-switch="counter.checkin"', false);
    }
}
