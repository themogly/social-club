<?php

namespace Tests\Feature\Memberships;

use App\Actions\Attendance\ResolveMemberEligibility;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Models\HeartbeatLog;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\ViewModels\SystemHealth;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 30 — proving the expiry sweep actually RUNS end to end: it lapses members and
 * a lapsed member is blocked; it is registered with the scheduler (not just runnable by
 * hand); and the health panel tracks THIS job specifically, so a silently-broken sweep
 * shows red even while the generic scheduler heartbeat stays green.
 */
class ExpirySweepVerificationTest extends TestCase
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

    public function test_a_lapsed_member_is_blocked_at_the_counter_and_flagged_at_check_in(): void
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'date_of_birth' => now()->subYears(30),
            'status' => MemberStatus::ACTIVE, 'carencia_ends_at' => now()->subDay(),
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        $membership = Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
            'expires_at' => now()->subDay(),
        ]);

        // Eligible before the sweep (active membership).
        $this->assertFalse((new ResolveMemberEligibility)->handle($member, $this->location, 'counter')->isBlocked());

        $this->artisan('memberships:sweep')->assertSuccessful();
        $this->assertSame(MembershipStatus::LAPSED, $membership->fresh()->status);

        // Now blocked at the counter AND the door — the membership rule fires (BLOCK on both).
        foreach (['counter', 'door'] as $surface) {
            $verdict = (new ResolveMemberEligibility)->handle($member, $this->location, $surface);
            $this->assertTrue($verdict->isBlocked(), "A lapsed member must be blocked at the {$surface}.");
            $this->assertContains('membership', array_column($verdict->blockingRules(), 'rule'));
        }
    }

    public function test_a_membership_in_the_expiring_soon_window_is_flagged_not_lapsed(): void
    {
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        $soon = Membership::factory()->create([
            'organisation_id' => $this->org->id,
            'member_id' => Member::factory()->create(['organisation_id' => $this->org->id])->id,
            'location_id' => $this->location->id, 'tier_id' => $tier->id,
            'status' => MembershipStatus::ACTIVE, 'expires_at' => now()->addDays(10),  // inside the 30d window
        ]);

        $this->artisan('memberships:sweep')->assertSuccessful();

        // Flagged, NOT jumped straight to lapsed, NOT left active.
        $this->assertSame(MembershipStatus::EXPIRING_SOON, $soon->fresh()->status);
    }

    public function test_the_sweep_is_registered_with_the_scheduler(): void
    {
        // Inspect the actual schedule — not just that the command runs when invoked by hand.
        $this->artisan('schedule:list')
            ->expectsOutputToContain('memberships:sweep')
            ->assertSuccessful();
    }

    public function test_the_health_panel_reflects_a_stale_expiry_sweep_specifically(): void
    {
        // The scheduler is alive, but the sweep has NEVER stamped → the sweep is red even though
        // the generic scheduler heartbeat is green. That gap is exactly what this exists to close.
        HeartbeatLog::beat('scheduler');
        $health = new SystemHealth;
        $this->assertFalse($health->scheduler()['stale']);
        $this->assertTrue($health->expirySweep()['stale']);

        // Running the sweep stamps its own heartbeat → the sweep goes green.
        $this->artisan('memberships:sweep')->assertSuccessful();
        $this->assertFalse((new SystemHealth)->expirySweep()['stale']);

        // …and an old sweep heartbeat is red again (daily job, ~26h threshold).
        HeartbeatLog::query()->component('memberships-sweep')->update(['ran_at' => now()->subDays(2)]);
        $this->assertTrue((new SystemHealth)->expirySweep()['stale']);
    }
}
