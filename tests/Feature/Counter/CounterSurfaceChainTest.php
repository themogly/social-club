<?php

namespace Tests\Feature\Counter;

use App\Enums\Role;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\CheckInScreen;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\MembershipCounter;
use App\Livewire\Counter\TillSession;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 187 — the operator surface must ask the CHAIN whether it is its turn.
 *
 * Reported live from a local install: the check-in screen showed the full-screen "¿Quién está trabajando?"
 * surface, the operator entered their PIN, and it was refused with "Sin sede activa." — with no way out,
 * because the sede switcher lives in the top bar and the surface was covering it at z-50.
 *
 * The cause was an ordering one. `CounterBlocker`'s chain (sede → operator → till → member) was already
 * correct and `rendersInPage()` already returned false for OPERATOR so 173's surface could own that step.
 * But `surfaceMode()` raised on "no operator" ALONE, without consulting the chain. On a fresh terminal —
 * which is every first run — neither sede nor operator is set, the chain correctly says SEDE, the in-page
 * sede blocker renders, and then the surface painted over it along with the only control that could fix it.
 *
 * That is a deadlock: no route out of the surface without an operator, and no route to an operator without
 * a sede.
 */
class CounterSurfaceChainTest extends TestCase
{
    use RefreshDatabase;

    /** Every screen that includes the surface — if the ordering is wrong in one it is wrong in all of them. */
    private const SCREENS = [
        CheckInScreen::class,
        MembershipCounter::class,
        TillSession::class,
        DispensaryPos::class,
        BarPos::class,
    ];

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
    }

    /** A staff user assigned to $count sedes, with none chosen — the fresh-terminal state. */
    private function staff(int $sedes): User
    {
        $user = User::factory()->create(['name' => 'Marta Operadora', 'pin' => Hash::make('4321')]);
        $user->assignRole(Role::STAFF->value);

        for ($i = 0; $i < $sedes; $i++) {
            $user->locations()->attach(Location::factory()->create(['organisation_id' => $this->org->id])->id);
        }

        return $user;
    }

    // --- The reported bug ----------------------------------------------------------------------------

    public function test_a_fresh_terminal_with_several_sedes_and_no_operator_shows_the_sede_step_not_the_pin(): void
    {
        // Two assigned sedes and none chosen: `mustChooseLocation`. This is the reported case — every first
        // run of a terminal for an operator who works in more than one sede.
        $user = $this->staff(sedes: 2);

        foreach (self::SCREENS as $screen) {
            $html = Livewire::actingAs($user)->test($screen)->html();

            $this->assertStringContainsString('data-surface-mode="none"', $html,
                class_basename($screen).' raised the operator surface while the chain was still on the sede step.');
            $this->assertStringContainsString('data-blocker="sede"', $html,
                class_basename($screen).' did not render the sede blocker it is supposed to be showing.');
        }
    }

    public function test_a_terminal_with_no_sede_assigned_at_all_shows_the_sede_step_not_the_pin(): void
    {
        $user = $this->staff(sedes: 0);

        foreach (self::SCREENS as $screen) {
            $html = Livewire::actingAs($user)->test($screen)->html();

            $this->assertStringContainsString('data-surface-mode="none"', $html,
                class_basename($screen).' raised the operator surface with no sede assigned.');
        }
    }

    public function test_the_sede_switcher_is_reachable_in_that_state(): void
    {
        // The whole deadlock was that the surface covered the top bar, which carries the only control that
        // can resolve the sede. Asserted on the full page, because the top bar lives in the layout.
        $user = $this->staff(sedes: 2);

        $html = $this->actingAs($user)->get(route('counter.checkin'))->assertOk()->getContent();

        $this->assertStringContainsString('data-counter-topbar', $html, 'The top bar — and the sede switcher — is unreachable.');
        $this->assertStringContainsString('data-surface-mode="none"', $html);
    }

    // --- The surface still owns its own step ---------------------------------------------------------

    public function test_with_a_sede_chosen_and_no_operator_the_surface_raises_as_before(): void
    {
        $user = $this->staff(sedes: 1);   // one assigned sede is adopted without ceremony

        foreach (self::SCREENS as $screen) {
            $html = Livewire::actingAs($user)->test($screen)->html();

            $this->assertStringContainsString('data-surface-mode="unidentified"', $html,
                class_basename($screen).' did not raise the operator surface once the sede step was met.');
        }
    }

    public function test_the_deadlock_is_gone_end_to_end(): void
    {
        // A fresh terminal with two sedes: choose one, then identify. Both steps must succeed in order.
        $user = $this->staff(sedes: 2);
        $chosen = $user->locations()->first();

        Livewire::actingAs($user)->test(CheckInScreen::class)
            ->assertSee('data-surface-mode="none"', false);

        // Choosing the sede goes through the validated switcher route, exactly as the top bar does.
        $this->actingAs($user)->post(route('counter.location'), ['location_id' => $chosen->id])
            ->assertRedirect();

        // Now the chain has reached the operator step, so the surface raises...
        Livewire::actingAs($user)->test(CheckInScreen::class)
            ->assertSee('data-surface-mode="unidentified"', false);

        // ...and the PIN it asks for is now one that can actually succeed.
        Livewire::actingAs($user)->test(CheckInScreen::class)
            ->set('operatorPin', '4321')
            ->call('unlockOperator');

        $this->assertSame($user->id, CounterOperator::id(), 'The PIN was refused after the sede was chosen.');
    }

    // --- The other two modes are unaffected ----------------------------------------------------------

    public function test_a_locked_terminal_still_shows_the_lock_regardless_of_the_chain(): void
    {
        // The lock is CLIENT state (prompt 120's idle timer) and must not depend on the chain: an operator
        // who has already identified once must always be able to get back in. So the surface element is
        // still rendered with no server mode, and Alpine's getter puts the lock ahead of it.
        $html = Livewire::actingAs($this->staff(sedes: 2))->test(CheckInScreen::class)->html();

        $this->assertStringContainsString('data-counter-surface', $html);
        $this->assertStringContainsString("if (\$store.counter.locked) return 'locked'", $html);
    }
}
