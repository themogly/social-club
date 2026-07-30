<?php

namespace Tests\Feature\Attendance;

use App\Actions\Attendance\CheckInMember;
use App\Actions\Attendance\CheckOutMember;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Exceptions\CheckInBlockedException;
use App\Models\CheckIn;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\EntryExitSheet;
use App\Support\Occupancy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckInTest extends TestCase
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
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id, 'capacity' => 2]);
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
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
        ]);

        return $member;
    }

    public function test_check_in_and_occupancy_track_a_mix_of_ins_and_outs(): void
    {
        $a = (new CheckInMember)->handle($this->member(), $this->location);
        (new CheckInMember)->handle($this->member(), $this->location);
        $this->assertSame(2, Occupancy::current($this->location));

        (new CheckOutMember)->handle($a);
        $this->assertSame(1, Occupancy::current($this->location));
    }

    public function test_a_member_cannot_be_checked_in_twice_concurrently(): void
    {
        $member = $this->member();
        $first = (new CheckInMember)->handle($member, $this->location);
        $second = (new CheckInMember)->handle($member, $this->location);

        $this->assertTrue($first->is($second)); // same open session, not a second one
        $this->assertSame(1, CheckIn::where('member_id', $member->id)->count());
    }

    public function test_aforo_blocks_the_next_check_in_at_capacity(): void
    {
        (new CheckInMember)->handle($this->member(), $this->location);
        (new CheckInMember)->handle($this->member(), $this->location); // capacity 2 reached

        $this->expectException(CheckInBlockedException::class);
        (new CheckInMember)->handle($this->member(), $this->location);
    }

    public function test_a_manager_can_override_a_door_block_and_it_is_logged(): void
    {
        (new CheckInMember)->handle($this->member(), $this->location);
        (new CheckInMember)->handle($this->member(), $this->location);

        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value); // has checkin.override

        $checkIn = (new CheckInMember)->handle($this->member(), $this->location, [
            'override' => true, 'override_by' => $manager, 'override_reason' => 'VIP guest of the board',
        ]);

        $this->assertNotNull($checkIn->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'checkin.override']);
    }

    public function test_auto_checkout_closes_open_sessions_and_is_idempotent(): void
    {
        $a = (new CheckInMember)->handle($this->member(), $this->location);

        $this->artisan('checkins:auto-checkout')->assertSuccessful();
        $a->refresh();
        $this->assertNotNull($a->checked_out_at);
        $this->assertTrue($a->auto_checked_out);

        // Idempotent — a second run closes nothing new.
        $this->artisan('checkins:auto-checkout')->assertSuccessful();
        $this->assertSame(0, Occupancy::current($this->location));
    }

    public function test_the_daily_sheet_matches_the_underlying_check_ins(): void
    {
        (new CheckInMember)->handle($this->member(), $this->location);
        (new CheckInMember)->handle($this->member(), $this->location);

        $sheet = EntryExitSheet::forDay($this->location);
        $this->assertCount(2, $sheet);
    }
}
