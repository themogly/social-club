<?php

namespace Tests\Feature\Counter;

use App\Actions\Attendance\CheckInMember;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 258, part A — "member checking should be optional, a toggle in admin for each location."
 *
 * Read as the check-in gate: `restrict_pos_to_checked_in`, a per-location toggle on LocationForm (prompt 44). It
 * already existed; this pins the tester's requirement end to end — OFF dispenses to a member who has not checked
 * in, ON refuses with "Regístrala primero", each sede on its own — and closes the one gap found: the gate ran only
 * when a member was SELECTED, so with the toggle ON a forged `memberId` (a public Livewire property) reached the
 * commit without a check-in. The commit now re-checks.
 */
class CheckInOptionalPerSedeTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private Location $norte;

    private Genetic $genetic;

    private Member $member;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->norte = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);

        $this->staff = User::factory()->create();
        $this->staff->assignRole(Role::STAFF->value);
        $this->staff->locations()->sync([$this->centro->id, $this->norte->id]);
        $this->actingAs($this->staff);
        CounterOperator::set($this->staff);

        $this->member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(),
            'daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000,
        ]);

        foreach ([$this->centro, $this->norte] as $sede) {
            GeneticPrice::create([
                'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $sede->id,
                'tier_id' => null, 'price_per_gram_cents' => 1000, 'active' => true,
            ]);
            Batch::factory()->create([
                'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $sede->id,
                'remaining_cg' => 100000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(),
            ]);
            Membership::factory()->create([
                'organisation_id' => $this->org->id, 'member_id' => $this->member->id, 'location_id' => $sede->id,
                'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
                'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
                'starts_at' => now()->subMonth(), 'expires_at' => now()->addYear(),
            ]);
            (new OpenTill)->handle($sede, 'POS-1', 10000);
        }
    }

    private function at(Location $sede): void
    {
        app(ActiveScope::class)->setLocation($sede->id);
        session(['counter.location_id' => $sede->id]);
    }

    private function restrict(Location $sede, bool $on): void
    {
        Settings::set('restrict_pos_to_checked_in', $on, SettingType::BOOL, $sede->id);
    }

    private function dispenseThroughTheScreen(): Testable
    {
        return Livewire::test(DispensaryPos::class)
            ->call('selectMember', $this->member->id)
            ->call('chooseGenetic', $this->genetic->id)
            ->set('weightInput', '1')
            ->call('addLine')
            ->call('commitDispensation');
    }

    public function test_off_dispenses_to_a_member_who_has_not_checked_in(): void
    {
        $this->at($this->centro);
        $this->restrict($this->centro, false);

        $this->dispenseThroughTheScreen()->assertSet('flashType', 'success');

        $this->assertSame(1, Dispensation::query()->withoutGlobalScopes()->count());
    }

    public function test_on_refuses_with_the_check_in_first_message_and_a_checked_in_member_passes(): void
    {
        $this->at($this->centro);
        $this->restrict($this->centro, true);

        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $this->member->id)
            ->assertSet('memberId', null)
            ->assertSet('flashMessage', __('El socio no ha registrado su entrada. Regístrala primero en recepción.'));

        (new CheckInMember)->handle($this->member, $this->centro, ['operator_id' => $this->staff->id]);
        $this->dispenseThroughTheScreen()->assertSet('flashType', 'success');
        $this->assertSame(1, Dispensation::query()->withoutGlobalScopes()->count());
    }

    public function test_each_sede_decides_for_itself(): void
    {
        $this->restrict($this->centro, false);
        $this->restrict($this->norte, true);

        $this->at($this->norte);
        Livewire::test(DispensaryPos::class)->call('selectMember', $this->member->id)->assertSet('memberId', null);

        $this->at($this->centro);
        $this->dispenseThroughTheScreen()->assertSet('flashType', 'success');
    }

    public function test_on_a_forged_member_id_cannot_skip_the_check_in_at_commit(): void
    {
        $this->at($this->centro);
        $this->restrict($this->centro, true);

        Livewire::test(DispensaryPos::class)
            ->set('memberId', $this->member->id) // never went through selectMember's gate
            ->set('basket', [['genetic_id' => $this->genetic->id, 'batch_id' => null, 'grams_cg' => 100, 'units' => null]])
            ->call('commitDispensation')
            ->assertSet('flashType', 'error');

        $this->assertSame(0, Dispensation::query()->withoutGlobalScopes()->count(), 'dispensed to a member who never checked in');
    }

    public function test_the_toggle_is_on_the_admin_location_form(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        Filament::setCurrentPanel('admin');

        Livewire::actingAs($owner)->test(EditLocation::class, ['record' => $this->centro->getRouteKey()])
            ->assertFormFieldExists('restrict_pos_to_checked_in')
            ->fillForm(['restrict_pos_to_checked_in' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue((bool) Settings::get('restrict_pos_to_checked_in', false, $this->centro->id));
        $this->assertFalse((bool) Settings::get('restrict_pos_to_checked_in', false, $this->norte->id), 'one sede\'s toggle leaked to another');
    }
}
