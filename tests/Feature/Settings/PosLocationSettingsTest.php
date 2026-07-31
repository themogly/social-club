<?php

namespace Tests\Feature\Settings;

use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\DispensaryPos;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 44 — the POS check-in / signature requirements are now genuinely PER-LOCATION:
 * DispensaryPos reads the same `restrict_pos_to_checked_in` / `signature_on_dispensation`
 * keys the LocationForm toggles write (Option A). Proves the toggle drives the enforced
 * behaviour end-to-end, not just that a value persists.
 */
class PosLocationSettingsTest extends TestCase
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

    private function operator(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::STAFF->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);
    }

    private function member(): Member
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

    public function test_the_location_checkin_requirement_gates_holding_a_member(): void
    {
        $this->operator();
        $member = $this->member(); // eligible, but NOT checked in at the door

        // Location requires check-in first → holding a non-checked-in member is refused.
        Settings::set('restrict_pos_to_checked_in', true, SettingType::BOOL, $this->location->id);
        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->assertSet('memberId', null)
            ->assertSet('flashType', 'error');

        // Turn the SAME location's toggle off → the identical member is now held fine.
        Settings::set('restrict_pos_to_checked_in', false, SettingType::BOOL, $this->location->id);
        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->assertSet('memberId', $member->id);
    }

    public function test_the_location_signature_requirement_drives_the_pos(): void
    {
        $this->operator();

        // The signature-required flag the commit gate reads flips with the LOCATION setting.
        Settings::set('signature_on_dispensation', true, SettingType::BOOL, $this->location->id);
        Livewire::test(DispensaryPos::class)->assertViewHas('requireSignature', true);

        Settings::set('signature_on_dispensation', false, SettingType::BOOL, $this->location->id);
        Livewire::test(DispensaryPos::class)->assertViewHas('requireSignature', false);
    }

    public function test_no_form_control_writes_a_setting_nothing_reads(): void
    {
        // Prompt 34's own verification, repeated: every `settings.*` toggle on the LocationForm is a key
        // real enforcement code reads. The retired org-wide pos_* keys are gone from the defaults.
        $this->assertArrayNotHasKey('pos_require_checked_in', Settings::DEFAULTS);
        $this->assertArrayNotHasKey('pos_signature_required', Settings::DEFAULTS);
        $this->assertArrayHasKey('restrict_pos_to_checked_in', Settings::DEFAULTS);
        $this->assertArrayHasKey('signature_on_dispensation', Settings::DEFAULTS);
    }
}
