<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Article;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 60 — clicking Charge/Registrar must ALWAYS produce an observable outcome: a sale, or a
 * stated reason it can't happen — never a silent dead control. The button is now disabled ONLY when
 * offline (its banner is driven by the same `online`), so every other blocked state reaches commit(),
 * which flashes its reason. One test per blocked state is the regression guard for the silent class.
 */
class ChargeAlwaysObservableTest extends TestCase
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

    private function operator(bool $withPin = true): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::STAFF->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        if ($withPin) {
            CounterOperator::set($user);
        }
    }

    private function openTill(): void
    {
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
    }

    private function article(): Article
    {
        return Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'price_cents' => 150, 'stock' => 10, 'active' => true,
        ]);
    }

    // --- Bar POS: every blocked state states its reason --------------------------

    public function test_bar_offline_states_a_reason(): void
    {
        $this->operator();
        $this->openTill();
        Livewire::test(BarPos::class)
            ->call('addArticle', $this->article()->id)
            ->set('offline', true)
            ->call('commit')
            ->assertSet('flashType', 'error')
            ->assertSee(__('Sin conexión: no se puede cobrar. La cesta se conserva hasta reconectar.'));
    }

    public function test_bar_without_a_pin_operator_states_a_reason(): void
    {
        $this->operator(withPin: false);
        $this->openTill();
        Livewire::test(BarPos::class)
            ->call('addArticle', $this->article()->id)
            ->call('commit')
            ->assertSet('flashType', 'error')
            ->assertSee(__('Identifícate con tu PIN antes de continuar.'));
    }

    public function test_bar_empty_basket_states_a_reason(): void
    {
        $this->operator();
        $this->openTill();
        Livewire::test(BarPos::class)
            ->call('commit')
            ->assertSet('flashType', 'error')
            ->assertSee(__('La cesta está vacía.'));
    }

    public function test_bar_no_open_till_states_a_reason(): void
    {
        $this->operator();
        Livewire::test(BarPos::class)
            ->call('addArticle', $this->article()->id)
            ->call('commit')
            ->assertSet('flashType', 'error')
            ->assertSee(__('No hay caja abierta en este terminal.'));
    }

    public function test_bar_wallet_without_a_socio_states_a_reason(): void
    {
        $this->operator();
        $this->openTill();
        Livewire::test(BarPos::class)
            ->call('addArticle', $this->article()->id)
            ->set('walletInput', '5,00')
            ->call('commit')
            ->assertSet('flashType', 'error')
            ->assertSee(__('El pago con monedero requiere un socio.'));
    }

    public function test_bar_manual_line_without_a_reference_states_a_reason(): void
    {
        $this->operator();
        $this->openTill();
        Livewire::test(BarPos::class)
            ->set('basket', [['type' => 'misc', 'description' => 'Propina', 'unit_price_cents' => 200, 'qty' => 1, 'reference' => '']])
            ->call('commit')
            ->assertSet('flashType', 'error')
            ->assertSee(__('Una línea manual requiere una referencia.'));
    }

    public function test_a_valid_bar_charge_commits_and_confirms(): void
    {
        $this->operator();
        $this->openTill();
        Livewire::test(BarPos::class)
            ->call('addArticle', $this->article()->id)
            ->call('commit')
            ->assertSet('flashType', 'success')
            ->assertSee(__('Última venta registrada'));
    }

    public function test_the_bar_charge_button_is_disabled_only_when_offline(): void
    {
        $this->operator();
        $html = Livewire::test(BarPos::class)->html();

        $this->assertStringContainsString('x-bind:disabled="! online"', $html);
        $this->assertStringNotContainsString('! online ||', $html); // no silent commitDisabled swallow
    }

    // --- Dispensary POS: the same guarantee --------------------------------------

    public function test_dispensary_without_a_socio_states_a_reason(): void
    {
        $this->operator();
        Livewire::test(DispensaryPos::class)
            ->call('commit')
            ->assertSet('flashType', 'error')
            ->assertSee(__('Identifica a un socio antes de registrar una dispensación.'));
    }

    public function test_dispensary_empty_basket_states_a_reason(): void
    {
        $this->operator();
        $this->openTill();
        $member = $this->eligibleMember();

        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('commit')
            ->assertSet('flashType', 'error')
            ->assertSee(__('La cesta está vacía.'));
    }

    public function test_the_dispensary_charge_button_is_disabled_only_when_offline(): void
    {
        $this->operator();
        $html = Livewire::test(DispensaryPos::class)->html();

        $this->assertStringContainsString('x-bind:disabled="! online"', $html);
        $this->assertStringNotContainsString('! online ||', $html);
    }

    private function eligibleMember(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(),
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);

        return $member;
    }
}
