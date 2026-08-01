<?php

namespace Tests\Feature\Till;

use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\TillSession as TillSessionScreen;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 102 — one till per sede is the DEFAULT: opening a caja asks only for the float and uses the sede's
 * single terminal; there is no picker and no free-typed terminal. A sede that runs several tills turns
 * `multiple_tills_enabled` on and gets the picker (covered by TillTerminalPickerTest).
 */
class SingleTillTest extends TestCase
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

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::MANAGER->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);

        return $user;
    }

    public function test_single_till_is_the_default_and_opening_asks_only_for_the_float(): void
    {
        $this->operator();

        $screen = Livewire::test(TillSessionScreen::class);
        $this->assertFalse($screen->instance()->multipleTills());   // default off
        $screen->assertSet('terminal', 'POS-1');                    // preset — no picker to fill

        // Open with ONLY a float; no terminal was chosen.
        $screen->set('floatInput', '100,00')->call('open')->assertHasNoErrors();

        $session = TillSession::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('POS-1', $session->terminal);
        $this->assertSame(10000, $session->float_cents->cents);     // €100,00 → 10000 cents
    }

    public function test_a_single_till_sede_uses_its_configured_terminal_name(): void
    {
        $this->location->update(['terminals' => ['Caja Principal']]);
        $this->operator();

        $screen = Livewire::test(TillSessionScreen::class);
        $screen->assertSet('terminal', 'Caja Principal');           // the sede's own name, not POS-1
        $screen->set('floatInput', '50')->call('open')->assertHasNoErrors();

        $this->assertSame('Caja Principal', TillSession::query()->withoutGlobalScopes()->firstOrFail()->terminal);
    }

    public function test_two_sedes_honour_their_own_setting(): void
    {
        $multi = Location::factory()->create(['organisation_id' => $this->org->id]);
        Settings::set('multiple_tills_enabled', true, SettingType::BOOL, $multi->id);

        // Single-till sede (default off).
        $this->operator();
        $this->assertFalse(Livewire::test(TillSessionScreen::class)->instance()->multipleTills());

        // Multi-till sede: same component, different sede → picker mode.
        $user = User::factory()->create();
        $user->assignRole(Role::MANAGER->value);
        $user->locations()->sync([$multi->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($multi->id);
        CounterOperator::set($user);

        $this->assertTrue(Livewire::test(TillSessionScreen::class)->instance()->multipleTills());
    }
}
