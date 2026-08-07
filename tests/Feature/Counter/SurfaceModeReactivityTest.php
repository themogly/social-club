<?php

namespace Tests\Feature\Counter;

use App\Enums\Role;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\CheckInScreen;
use App\Livewire\Counter\TillSession;
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
 * Prompt 188 — the surface stayed up after a successful PIN until you refreshed the page.
 *
 * The mode was snapshotted into Alpine's `x-data` at init (`serverMode: @js($surfaceMode)`). Livewire
 * preserves the DOM across a re-render, so `x-data` never runs again: after `unlockOperator()` the server's
 * mode was null while the client still held 'unidentified'. The state was right; only the client's copy was
 * stale.
 *
 * `$surfaceModeState` is the property the client now reads through `$wire`, refreshed on every render. These
 * assert it against the SAME component instance across an interaction — which is what "without a reload"
 * means in a Livewire test: no re-mount, no fresh `x-data`.
 */
class SurfaceModeReactivityTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private User $device;

    private User $operator;

    private User $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);

        $this->device = $this->staff('Device Login', null);
        $this->operator = $this->staff('Marta Operadora', '4321');
        $this->second = $this->staff('Luis Segundo', '8765');
    }

    private function staff(string $name, ?string $pin): User
    {
        $user = User::factory()->create(['name' => $name, 'pin' => $pin === null ? null : Hash::make($pin)]);
        $user->assignRole(Role::MANAGER->value);
        $user->locations()->attach($this->location->id);

        return $user;
    }

    // --- The reported bug ----------------------------------------------------------------------------

    public function test_identifying_clears_the_surface_in_the_same_interaction(): void
    {
        Livewire::actingAs($this->device)->test(CheckInScreen::class)
            ->assertSet('surfaceModeState', 'unidentified')
            ->set('operatorPin', '4321')
            ->call('unlockOperator')
            // No re-mount between these two assertions: this is the value the client reads, and it changed.
            ->assertSet('surfaceModeState', null);
    }

    public function test_switching_operator_raises_the_surface_again_in_the_same_interaction(): void
    {
        CounterOperator::set($this->operator);

        Livewire::actingAs($this->device)->test(CheckInScreen::class)
            ->assertSet('surfaceModeState', null)
            ->call('switchOperator')
            ->assertSet('surfaceModeState', 'unidentified')
            ->set('operatorPin', '8765')
            ->call('unlockOperator')
            ->assertSet('surfaceModeState', null);
    }

    public function test_locking_and_unlocking_behave_the_same_way(): void
    {
        CounterOperator::set($this->operator);

        Livewire::actingAs($this->device)->test(TillSession::class)
            ->assertSet('surfaceModeState', null)
            ->call('lockCounter')
            ->assertSet('surfaceModeState', 'unidentified')
            ->set('operatorPin', '4321')
            ->call('unlockOperator')
            ->assertSet('surfaceModeState', null);
    }

    public function test_entering_and_leaving_handed_over_mode_updates_the_client(): void
    {
        CounterOperator::set($this->operator);

        Livewire::actingAs($this->device)->test(TillSession::class)
            ->assertSet('surfaceModeState', null)
            ->call('beginHandover')
            ->assertSet('surfaceModeState', 'handover')
            ->set('operatorPin', '4321')
            ->call('unlockOperator')
            ->assertSet('surfaceModeState', null);

        $this->assertFalse(CounterHandover::active());
    }

    // --- Precedence is unchanged ---------------------------------------------------------------------

    public function test_handed_over_outranks_everything_the_server_can_say(): void
    {
        // No operator AND a handover: the server must still report handover, never 'unidentified' — an
        // applicant must not be shown a lock or a PIN prompt mid-form.
        CounterOperator::set($this->operator);
        Livewire::actingAs($this->device)->test(TillSession::class)->call('beginHandover');

        $this->assertNull(CounterOperator::id());
        Livewire::actingAs($this->device)->test(CheckInScreen::class)
            ->assertSet('surfaceModeState', 'handover');
    }

    public function test_the_client_side_lock_still_outranks_unidentified(): void
    {
        // The idle lock is client state and deliberately sits ABOVE the server's mode in the getter — an
        // operator who has been identified once must see the lock, not a fresh identify prompt.
        $html = Livewire::actingAs($this->device)->test(CheckInScreen::class)->html();

        $this->assertMatchesRegularExpression(
            "/if \(\\\$wire\.surfaceModeState === 'handover'\) return 'handover'\s*\n\s*if \(\\\$store\.counter\.locked\) return 'locked'/",
            $html,
            'The precedence handover > locked > server mode was not preserved.'
        );
    }

    // --- 173's preserved state survives every transition ---------------------------------------------

    public function test_a_basket_in_progress_survives_every_transition(): void
    {
        CounterOperator::set($this->operator);

        // A BarPos `misc` line: real basket state that needs no stock fixture, so this test is about the
        // surface transitions and nothing else. The basket lives on the component either way.
        $basket = [['type' => 'misc', 'description' => 'Propina', 'unit_price_cents' => 200, 'qty' => 1, 'reference' => '']];

        Livewire::actingAs($this->device)->test(BarPos::class)
            ->set('basket', $basket)
            ->call('lockCounter')->assertSet('basket', $basket)
            ->set('operatorPin', '4321')->call('unlockOperator')->assertSet('basket', $basket)
            ->call('switchOperator')->assertSet('basket', $basket)
            ->set('operatorPin', '8765')->call('unlockOperator')->assertSet('basket', $basket)
            ->call('beginHandover')->assertSet('basket', $basket)
            ->set('operatorPin', '4321')->call('unlockOperator')->assertSet('basket', $basket);
    }

    // --- the surface must not go on swallowing input once the server has cleared it -------------------

    public function test_the_counter_beneath_is_reachable_immediately_after_identifying(): void
    {
        // The surface is `fixed inset-0 z-50` and opaque, so while it is stale and up it covers the whole
        // viewport and every tap lands on it — the counter beneath looks dead. That makes a stale surface a
        // plausible-looking cause of "I pressed the button and nothing happened", which is why this assertion
        // exists: it is the difference between a cosmetic bug and a correctness one.
        //
        // (It was in fact suspected of causing exactly that on the bar, and it was NOT the cause — that was
        // prompt 195's `$wire` alias collision, a different bug in a different file. The two are told apart
        // by one measurement: a tap swallowed by an overlay produces NO Livewire request at all, whereas the
        // charge button produced one and got a 200 back. See audits/reports/192-bar-charge-investigation.md.)
        CounterOperator::set($this->operator);

        $screen = Livewire::actingAs($this->device)->test(BarPos::class)
            ->call('switchOperator')
            ->assertSet('surfaceModeState', 'unidentified')     // surface up: input would be swallowed
            ->set('operatorPin', '4321')
            ->call('unlockOperator')
            ->assertSet('surfaceModeState', null);              // …and down again, in the same interaction

        // No reload in between: the very next thing the operator does reaches the counter and is answered.
        $screen->call('commitOrder');

        $this->assertNotNull($screen->get('flashMessage'),
            'The counter beneath the surface produced no outcome — a control under a cleared surface must be reachable.');
    }
}
