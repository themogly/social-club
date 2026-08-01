<?php

namespace Tests\Feature\Members;

use App\Actions\Members\CancelMembership;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\MembersRegister;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 80 — a member's voluntary departure (baja). Before this there was no exit: MembershipStatus::CANCELLED
 * was assigned nowhere and left_at could not be populated on the ordinary path, so the libro de socios printed
 * "—" in the Departure column. CancelMembership fills both.
 */
class CancelMembershipTest extends TestCase
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

    private function userWithRole(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);

        return $user;
    }

    private function activeMember(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id,
            'status' => MemberStatus::ACTIVE,
            'joined_at' => now()->subMonths(6),
            'left_at' => null,
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
        ]);

        return $member;
    }

    public function test_a_baja_cancels_the_membership_records_the_leave_date_and_shows_in_the_libro(): void
    {
        $manager = $this->userWithRole(Role::MANAGER);
        $member = $this->activeMember();
        $membership = $member->memberships()->firstOrFail();

        (new CancelMembership)->handle($member, $manager, 'Se muda de ciudad');

        $member->refresh();
        $this->assertSame(MemberStatus::INACTIVE, $member->status);
        $this->assertNotNull($member->left_at);
        $this->assertSame(MembershipStatus::CANCELLED, $membership->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.membership.cancelled']);

        // The libro de socios now shows a departure date instead of "—".
        $rows = MembersRegister::asAt($this->org->id, now()->toDateString());
        $row = collect($rows)->firstWhere('member_no', $member->member_no);
        $this->assertNotNull($row);
        $this->assertSame(now()->toDateString(), $row['baja']);
    }

    public function test_staff_cannot_record_a_baja(): void
    {
        // STAFF has members.create/view but NOT members.edit — a baja is an edit-level act.
        $staff = $this->userWithRole(Role::STAFF);
        $member = $this->activeMember();

        $this->expectException(AuthorizationException::class);
        (new CancelMembership)->handle($member, $staff, 'no permission');
    }

    public function test_it_refuses_to_baja_a_member_who_has_already_left(): void
    {
        $manager = $this->userWithRole(Role::MANAGER);
        $member = $this->activeMember();
        (new CancelMembership)->handle($member, $manager, 'first baja');

        $this->expectException(RuntimeException::class);
        (new CancelMembership)->handle($member->fresh(), $manager, 'second baja');
    }

    public function test_a_baja_requires_a_reason(): void
    {
        $manager = $this->userWithRole(Role::MANAGER);
        $member = $this->activeMember();

        $this->expectException(RuntimeException::class);
        (new CancelMembership)->handle($member, $manager, '   ');
    }
}
