<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\ApplicationStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Livewire\Counter\CheckInScreen;
use App\Livewire\Counter\CounterHome;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\CounterScreens;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ChangesRolePermissions;
use Tests\TestCase;

/**
 * Prompt 337 — Ben: "Can we make a way to hide Reception? … put it in the location settings to turn it off." A per-sede
 * `reception_enabled` (default on): off, Recepción's tile and route go, the hub's check-in figures go, a *Nuevo socio*
 * tile takes the place (Socios with the sign-up open), and the check-in gate cannot be on — or nobody could be served.
 */
class ReceptionPerSedeTest extends TestCase
{
    use ChangesRolePermissions, RefreshDatabase;

    private Organisation $org;

    private Location $greenIndoor;

    private Location $centro;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->greenIndoor = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Green Indoor']);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        Settings::set('reception_enabled', false, SettingType::BOOL, $this->greenIndoor->id);
        foreach ([$this->greenIndoor, $this->centro] as $sede) {
            (new OpenTill)->handle($sede, 'POS-1', 10000);
        }
        $this->manager = $this->operator(Role::MANAGER);
        $this->at($this->greenIndoor);
    }

    private function operator(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->greenIndoor->id, $this->centro->id]);
        $this->actingAs($user);
        CounterOperator::set($user);

        return $user;
    }

    private function at(Location $sede): void
    {
        session(['counter.location_id' => $sede->id]);
        app(ActiveScope::class)->setLocation($sede->id);
        Settings::flush();
    }

    private function member(Location $at): Member
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay()]);
        Membership::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $at->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0]);

        return $member;
    }

    // --- 1–2. Off at Green Indoor, on at Centro --------------------------------------------------------------------------

    public function test_off_the_counter_has_no_reception_and_the_hub_no_check_in_figures(): void
    {
        $this->assertNotContains('counter.checkin', array_column(CounterScreens::reachableFor($this->manager), 'route'));

        $hub = Livewire::test(CounterHome::class)->html();
        $this->assertStringNotContainsString('data-counter-home-tile="counter.checkin"', $hub);
        $this->assertStringNotContainsString('data-panel="presence"', $hub);
        $this->assertStringNotContainsString('data-figure="check_ins"', $hub);

        Livewire::test(CheckInScreen::class)->assertRedirect(route('counter.home'));
        $this->get(route('counter.checkin'))->assertRedirect(route('counter.home'))
            ->assertSessionHas('counterNotice', __('Recepción está desactivada en esta sede.'));
        $this->get(route('counter.home'))->assertSee(__('Recepción está desactivada en esta sede.'))->assertDontSee('whos-inside');
    }

    public function test_the_same_person_at_a_sede_with_reception_on_sees_everything_as_before(): void
    {
        $this->at($this->centro);

        $this->assertContains('counter.checkin', array_column(CounterScreens::reachableFor($this->manager), 'route'));
        $hub = Livewire::test(CounterHome::class)->html();
        $this->assertStringContainsString('data-counter-home-tile="counter.checkin"', $hub);
        $this->assertStringContainsString('data-panel="presence"', $hub);
        $this->assertStringContainsString('data-figure="check_ins"', $hub);
        $this->assertStringNotContainsString('data-counter-home-tile-new-member', $hub);
        Livewire::test(CheckInScreen::class)->assertNoRedirect();
    }

    // --- 3–4. The gate can't be on ------------------------------------------------------------------------------------------

    public function test_with_reception_off_a_member_who_has_not_checked_in_can_be_served_whatever_the_stored_gate(): void
    {
        Settings::set('restrict_pos_to_checked_in', true, SettingType::BOOL, $this->greenIndoor->id);
        Settings::set('restrict_pos_to_checked_in', true, SettingType::BOOL, $this->centro->id);

        Livewire::test(DispensaryPos::class)->call('selectMember', $this->member($this->greenIndoor)->id)->assertSet('memberId', fn ($v): bool => $v !== null);

        $this->at($this->centro); // reception on: the gate still holds
        Livewire::test(DispensaryPos::class)->call('selectMember', $this->member($this->centro)->id)->assertSet('memberId', null);
    }

    public function test_the_sede_form_disables_and_forces_off_the_gate_while_reception_is_off(): void
    {
        $owner = $this->operator(Role::OWNER);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(EditLocation::class, ['record' => $this->greenIndoor->getRouteKey()])
            ->assertFormFieldExists('reception_enabled', fn ($f): bool => $f->getLabel() === __('Mostrar Recepción en el mostrador'))
            ->assertFormSet(['reception_enabled' => false])
            ->assertFormFieldIsDisabled('restrict_pos_to_checked_in')
            ->assertSee(__('Requiere Recepción.'))
            ->fillForm(['restrict_pos_to_checked_in' => true])->call('save')->assertHasNoFormErrors();

        Settings::flush();
        $this->assertFalse((bool) Settings::get('restrict_pos_to_checked_in', false, $this->greenIndoor->id));
        $this->assertFalse((bool) Settings::get('reception_enabled', true, $this->greenIndoor->id));
        $this->assertTrue((bool) Settings::get('reception_enabled', true, $this->centro->id), 'on by default');
        unset($owner);
    }

    // --- 4a. The *Nuevo socio* tile ----------------------------------------------------------------------------------------------

    public function test_with_reception_off_a_nuevo_socio_tile_takes_its_place_and_opens_the_sign_up(): void
    {
        MemberApplication::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->greenIndoor->id,
            'status' => ApplicationStatus::PENDING, 'submitted_at' => now(), 'payload' => ['first_name' => 'Ana', 'last_name' => 'Ruiz']]);

        $hub = Livewire::test(CounterHome::class);
        $hub->assertSeeHtml('data-counter-home-tile-new-member')->assertSee(__('Nuevo socio'))->assertSee(__('Alta y solicitudes pendientes'))
            ->assertSeeHtml('href="'.e(route('counter.members', ['alta' => 'nuevo'])).'"')
            ->assertSeeHtml('data-new-member-count');
        $tiles = $hub->instance()->secondaryTiles();
        $this->assertSame('new-member', $tiles[0]['key'] ?? null, 'the tile takes Recepción\'s place (first)');

        Livewire::withQueryParams(['alta' => 'nuevo'])->test(MembershipCounter::class)->assertSet('altaOpen', true);

        // Without applications.review (staff hold it since 174; taken away here): no tile, and the link opens nothing.
        $this->setRolePermission(Role::STAFF, 'applications.review', false);
        $this->operator(Role::STAFF);
        $this->assertStringNotContainsString('data-counter-home-tile-new-member', Livewire::test(CounterHome::class)->html());
        Livewire::withQueryParams(['alta' => 'nuevo'])->test(MembershipCounter::class)->assertSet('altaOpen', false);
    }

    // --- 5–6. Unaffected --------------------------------------------------------------------------------------------------------------

    public function test_the_landing_never_picks_reception_where_it_is_off(): void
    {
        Settings::set('counter_landing', 'screen', SettingType::STRING);
        Settings::flush();

        $this->assertNotSame('counter.checkin', CounterScreens::landingRouteFor($this->manager));
        $this->at($this->centro);
        $this->assertSame('counter.checkin', CounterScreens::landingRouteFor($this->manager));
    }

    public function test_the_auto_checkout_sweep_runs_cleanly_with_reception_off(): void
    {
        $this->artisan('checkins:auto-checkout')->assertExitCode(0);
    }
}
