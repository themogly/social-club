<?php

namespace Tests\Feature\Memberships;

use App\Actions\Memberships\EnrolMembership;
use App\Actions\Memberships\TransferMembership;
use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Mail\MembershipReminderMail;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MembershipLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private MembershipTier $tier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'default_fee_cents' => 2000]);
    }

    private function membership(string $expiresAt, MembershipStatus $status = MembershipStatus::ACTIVE): Membership
    {
        return Membership::factory()->create([
            'organisation_id' => $this->org->id,
            'member_id' => Member::factory()->create(['organisation_id' => $this->org->id])->id,
            'location_id' => $this->location->id,
            'tier_id' => $this->tier->id,
            'expires_at' => $expiresAt,
            'status' => $status,
        ]);
    }

    public function test_expiry_sweep_flips_only_lapsed_and_is_idempotent(): void
    {
        $lapsed = $this->membership(now()->subDay()->toDateTimeString());
        $soon = $this->membership(now()->addDays(10)->toDateTimeString());
        $future = $this->membership(now()->addYear()->toDateTimeString());

        $this->artisan('memberships:sweep')->assertSuccessful();

        $this->assertSame(MembershipStatus::LAPSED, $lapsed->fresh()->status);
        $this->assertSame(MembershipStatus::EXPIRING_SOON, $soon->fresh()->status);
        $this->assertSame(MembershipStatus::ACTIVE, $future->fresh()->status);

        // Idempotent: a second run leaves them unchanged.
        $this->artisan('memberships:sweep')->assertSuccessful();
        $this->assertSame(MembershipStatus::LAPSED, $lapsed->fresh()->status);
    }

    public function test_renewal_reminders_send_once_per_member_per_period(): void
    {
        Mail::fake();
        $this->membership(now()->addDays(5)->toDateTimeString()); // within the 7-day lead

        $this->artisan('memberships:sweep');
        $this->artisan('memberships:sweep'); // retry

        Mail::assertQueued(MembershipReminderMail::class, 1); // queued exactly once, not twice (prompt 149)
    }

    public function test_fee_override_requires_permission_and_records_the_overrider(): void
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);

        try {
            (new EnrolMembership)->handle($member, $this->location, $this->tier, ['fee_cents' => 5000, 'actor' => $staff]);
            $this->fail('Staff should not override the fee.');
        } catch (AuthorizationException) {
            // expected
        }

        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $membership = (new EnrolMembership)->handle($member, $this->location, $this->tier, ['fee_cents' => 5000, 'actor' => $owner, 'fee_override_reason' => 'Concession']);

        $this->assertSame(5000, $membership->fee_cents->cents);
        $this->assertSame($owner->id, $membership->fee_override_by);
    }

    public function test_transfer_moves_the_membership_and_is_audited(): void
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        $membership = (new EnrolMembership)->handle($member, $this->location, $this->tier);
        $other = Location::factory()->create(['organisation_id' => $this->org->id]);

        (new TransferMembership)->handle($membership, $other);

        $this->assertSame($other->id, $membership->fresh()->location_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'membership.transferred']);
    }
}
