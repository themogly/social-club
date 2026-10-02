<?php

namespace Tests\Feature\Counter;

use App\Enums\Role;
use App\Livewire\Counter\CheckInScreen;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 286 — every PIN pad says it is checking, answers "Hola, Marta" or how many attempts are left, and can never
 * send a second request while the first is in flight (each retype that lands wrong costs an attempt). The behaviour is
 * written once (`window.counterPinCheck` in resources/js/app.js) and every PIN entry uses it; the one-request-in-flight
 * rule is proven in the browser (tests/Browser/prove-286-pin-pad.mjs), these pin the wiring.
 */
class PinPadStatesTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    private User $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $this->location = Location::factory()->create(['organisation_id' => $org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);

        $this->device = User::factory()->create(['name' => 'Tablet']);
        $this->device->assignRole(Role::MANAGER->value);
        $this->device->locations()->attach($this->location->id);

        $marta = User::factory()->create(['name' => 'Marta Operadora', 'pin' => '4321']);
        $marta->assignRole(Role::STAFF->value);
        $marta->locations()->attach($this->location->id);
    }

    public function test_every_pin_entry_uses_the_one_shared_checking_behaviour(): void
    {
        $js = (string) file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString('window.counterPinCheck', $js);
        $this->assertMatchesRegularExpression('/if \(this\.checking \|\| this\.holding\) return null;/', $js, 'a second submit while checking is impossible');
        $this->assertMatchesRegularExpression('/finally \{\s*this\.checking = false;/', $js, 'the checking state always clears');

        $surface = (string) file_get_contents(resource_path('views/livewire/counter/partials/counter-surface.blade.php'));
        $supervisor = (string) file_get_contents(resource_path('views/livewire/counter/partials/authorise-with-pin.blade.php'));
        $till = (string) file_get_contents(resource_path('views/livewire/counter/till-session.blade.php'));

        foreach (['surface' => $surface, 'supervisor' => $supervisor, 'till handover' => $till] as $name => $view) {
            $this->assertStringContainsString('counterPinCheck()', $view, "the {$name} PIN does not use the shared behaviour");
            $this->assertStringContainsString("__('Comprobando…')", $view, "the {$name} PIN has no checking label");
        }

        // The surface pad: dots stay while checking; keys, Borrar, backspace and confirm all follow ONE disabled state.
        // Prompt 338 — the same pad also confirms the top bar's «Fichar entrada».
        $this->assertStringContainsString('checkPin(() => clockOut ? $wire.confirmClockOut() : (clockIn ? $wire.confirmClockIn() : $wire.unlockOperator()))', $surface);
        // The keys live in the one pad component (prompt 353); the confirm stays on the surface.
        $keys = (string) file_get_contents(resource_path('views/components/counter/pin-keys.blade.php'));
        $this->assertSame(5, substr_count($surface.$keys, 'x-bind:disabled="keysLocked"'));
        $this->assertStringContainsString('get keysLocked() { return this.pinBusy() || $wire.pinLocked }', $surface);
        $this->assertStringContainsString('data-pin-status role="status"', $surface);
        $this->assertStringNotContainsString('$wire.operatorPin = this.pin; this.pin = \'\'', $surface, 'the dots must not empty before the answer');
    }

    public function test_the_pad_exposes_its_states_and_a_wrong_pin_says_what_is_left(): void
    {
        $screen = Livewire::actingAs($this->device)->test(CheckInScreen::class);
        $html = $screen->html();

        $this->assertStringContainsString('data-pin-pad', $html);
        $this->assertStringContainsString("checking ? 'checking' : (holding ? 'success' : (shaking ? 'error' : 'ready'))", $html);

        $screen->set('operatorPin', '0000')->call('unlockOperator')
            ->assertReturned(['ok' => false])
            ->assertSet('operatorFeedback', trans_choice('PIN incorrecto. Te queda :count intento.|PIN incorrecto. Te quedan :count intentos.', 4, ['count' => 4]))
            ->assertSee('role="alert"', false);

        $screen->set('operatorPin', '4321')->call('unlockOperator')
            ->assertReturned(['ok' => true, 'name' => 'Marta']);
    }

    public function test_the_lockout_reaches_the_pad_as_live_state(): void
    {
        $screen = Livewire::actingAs($this->device)->test(CheckInScreen::class)->assertSet('pinLocked', false);

        foreach (range(1, 5) as $ignored) {
            $screen->set('operatorPin', '0000')->call('unlockOperator');
        }

        $screen->assertSet('pinLocked', true)
            ->assertSet('operatorFeedback', __('Demasiados intentos. Espera un momento antes de reintentar.'))
            ->assertSee('data-pin-lockout', false);
    }
}
