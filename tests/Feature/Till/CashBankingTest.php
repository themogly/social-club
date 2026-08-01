<?php

namespace Tests\Feature\Till;

use App\Actions\Till\OpenTill;
use App\Enums\CashMovementType;
use App\Enums\Role;
use App\Livewire\Counter\TillSession;
use App\Models\CashMovement;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\TillSummary;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 81 — wiring the previously-dead `cash.bank`. Banking cash OUT of the drawer to the bank is a
 * sensitive move (money leaving), so the BANKED till movement is gated on cash.bank (manager+). A STAFF
 * operator can open the drawer and record IN/OUT but can never bank — even by forcing the property past the
 * UI, the server refuses.
 */
class CashBankingTest extends TestCase
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

    private function operator(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user); // pass the PIN-operator guard so the PERMISSION is what decides

        return $user;
    }

    private function bankedCount(): int
    {
        return CashMovement::query()->withoutGlobalScopes()
            ->where('type', CashMovementType::BANKED->value)->count();
    }

    public function test_a_manager_can_bank_cash_and_it_leaves_the_drawer(): void
    {
        $this->operator(Role::MANAGER); // holds cash.bank + till.open
        $session = (new OpenTill)->handle($this->location, 'POS-1', 10000); // €100 float

        Livewire::test(TillSession::class)
            ->assertSet('terminal', 'POS-1')
            ->set('movementType', 'BANKED')
            ->set('movementAmount', '30') // €30,00 to the bank
            ->call('recordMovement')
            ->assertSet('flashType', 'success');

        $this->assertSame(1, $this->bankedCount());
        $this->assertSame(7000, TillSummary::expectedCents($session)); // €100 − €30 banked
    }

    public function test_staff_cannot_bank_cash_even_by_forcing_the_type(): void
    {
        $this->operator(Role::STAFF); // till.open, but NOT cash.bank
        $session = (new OpenTill)->handle($this->location, 'POS-1', 10000);

        Livewire::test(TillSession::class)
            ->assertSet('terminal', 'POS-1')
            ->set('movementType', 'BANKED') // forced past the UI, which hides the option
            ->set('movementAmount', '30')
            ->call('recordMovement')
            ->assertSet('flashType', 'error');

        $this->assertSame(0, $this->bankedCount());
        $this->assertSame(10000, TillSummary::expectedCents($session)); // drawer untouched
    }
}
