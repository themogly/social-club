<?php

namespace Tests\Feature\Counter;

use App\Actions\Members\IssueMemberToken;
use App\Actions\Till\OpenTill;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\CheckInScreen;
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
 * Prompt 35 — camera QR scan. The camera is a per-location OPT-IN progressive enhancement:
 * off by default, surfaced only when the setting is on, and a decoded token routes through
 * the SAME server lookup as the wedge scanner. The JS decode itself is verified in a browser
 * (no headless camera); these tests cover the gate + the server wiring.
 */
class CameraScanTest extends TestCase
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
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id, 'capacity' => 10]);
    }

    private function operator(Role $role = Role::STAFF): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        CounterOperator::set($user);
        app(ActiveScope::class)->setLocation($this->location->id);

        return $user;
    }

    private function eligibleMember(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id,
            'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30),
            'carencia_ends_at' => now()->subDay(),
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id,
            'member_id' => $member->id,
            'location_id' => $this->location->id,
            'tier_id' => $tier->id,
            'status' => MembershipStatus::ACTIVE,
            'fee_cents' => 0,
        ]);

        return $member;
    }

    public function test_camera_scan_is_off_by_default_and_the_trigger_is_absent(): void
    {
        $this->assertFalse((bool) Settings::get('camera_scan_enabled', false));

        $this->actingAs($this->operator());

        Livewire::test(CheckInScreen::class)
            ->assertOk()
            ->assertDontSee(__('Escanear con cámara'));
    }

    public function test_enabling_the_setting_surfaces_the_camera_trigger_on_both_screens(): void
    {
        Settings::set('camera_scan_enabled', true, SettingType::BOOL);
        $this->actingAs($this->operator(Role::OWNER)); // OWNER holds both checkin.manage and pos.use

        Livewire::test(CheckInScreen::class)
            ->assertOk()
            ->assertSee(__('Escanear con cámara'))
            ->assertSee('cameraScan(', false); // the Alpine behaviour is wired in

        // Prompt 175: on the dispensary the camera lives in the member-identify partial, which the member
        // blocking state carries — reachable once the till step above it in the chain is met.
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        Livewire::test(DispensaryPos::class)
            ->assertOk()
            ->assertSee(__('Escanear con cámara'));
    }

    public function test_a_camera_decoded_token_identifies_the_member_through_the_same_lookup(): void
    {
        $member = $this->eligibleMember();
        $token = (new IssueMemberToken)->handle($member);

        $this->actingAs($this->operator());

        Livewire::test(CheckInScreen::class)
            ->call('submitCameraScan', $token)   // the camera hands the decoded token straight in
            ->assertSet('memberId', $member->id)
            ->assertSet('scanned', true)
            ->assertSee($member->member_no);
    }

    public function test_an_unrecognised_camera_token_flashes_and_holds_no_member(): void
    {
        $this->actingAs($this->operator());

        Livewire::test(CheckInScreen::class)
            ->call('submitCameraScan', 'not-a-real-token')
            ->assertSet('memberId', null)
            ->assertSee(__('Tarjeta no reconocida. Inténtalo de nuevo o busca por nombre.'));
    }
}
