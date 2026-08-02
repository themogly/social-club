<?php

namespace Tests\Feature\Dashboard;

use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Period;
use App\Support\Settings;
use App\Support\StockCeiling;
use App\ViewModels\Dashboard as DashboardData;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 134 — the legal stock headroom surfaced per sede. Presentation of what StockCeiling already computes
 * (and prompt 110 enforces at intake): the figure shown here and the one intake blocks on are the SAME number.
 */
class CeilingHeadroomTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
    }

    private function owner(): User
    {
        $u = User::factory()->create();
        $u->assignRole(Role::OWNER->value);

        return $u;
    }

    /** Create $count members with an ACTIVE membership at $location (what StockCeiling counts). */
    private function activeMembersAt(Location $location, int $count): void
    {
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        for ($i = 0; $i < $count; $i++) {
            $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE]);
            Membership::factory()->create([
                'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $location->id,
                'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
            ]);
        }
    }

    private function stockAt(Location $location, int $cg): void
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id,
            'location_id' => $location->id, 'remaining_cg' => $cg, 'status' => BatchStatus::OPEN,
        ]);
    }

    private function headroomFor(User $user, Location $location): array
    {
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation(null); // owner rollup → all sedes

        return collect(DashboardData::for($user, Period::today())->ceilingHeadroom())
            ->firstWhere('location', $location->name);
    }

    public function test_headroom_equals_ceiling_minus_on_site_and_matches_stockceiling(): void
    {
        $loc = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->activeMembersAt($loc, 10);              // 10 × 350cg × 5 = 17500cg ceiling
        $this->stockAt($loc, 5000);                    // 50 g on-site

        $ceiling = StockCeiling::forLocation($loc->fresh());
        $row = $this->headroomFor($this->owner(), $loc);

        $this->assertSame($ceiling['ceiling_cg'] - $ceiling['on_site_cg'], $row['headroom_cg']);
        $this->assertSame($ceiling['ceiling_cg'], $row['ceiling_cg']);
        $this->assertSame($ceiling['on_site_cg'], $row['on_site_cg']);
        $this->assertSame($ceiling['active_members'], $row['active_members']);
        $this->assertFalse($row['exceeded']);
    }

    public function test_two_sedes_with_different_memberships_show_different_headroom(): void
    {
        $a = Location::factory()->create(['organisation_id' => $this->org->id]);
        $b = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->activeMembersAt($a, 10);
        $this->activeMembersAt($b, 4);
        $owner = $this->owner();

        $this->assertGreaterThan($this->headroomFor($owner, $b)['headroom_cg'], $this->headroomFor($owner, $a)['headroom_cg']);
    }

    public function test_a_sede_over_the_limit_shows_the_overage_not_just_a_flag(): void
    {
        $loc = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->activeMembersAt($loc, 2);   // ceiling = 2 × 350 × 5 = 3500cg
        $this->stockAt($loc, 10000);       // 100 g on-site → over by 6500cg

        $row = $this->headroomFor($this->owner(), $loc);

        $this->assertTrue($row['exceeded']);
        $this->assertSame(0, $row['headroom_cg']);
        $this->assertSame(10000 - 3500, $row['over_cg']);
    }

    public function test_adding_an_active_member_raises_only_that_sedes_headroom(): void
    {
        $a = Location::factory()->create(['organisation_id' => $this->org->id]);
        $b = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->activeMembersAt($a, 5);
        $this->activeMembersAt($b, 5);
        $owner = $this->owner();

        $beforeA = $this->headroomFor($owner, $a)['headroom_cg'];
        $beforeB = $this->headroomFor($owner, $b)['headroom_cg'];

        $this->activeMembersAt($a, 1); // +1 active member at A only

        $dailyLimit = (int) Settings::get('daily_limit_cg');
        $days = (int) Settings::get('stock_ceiling_days', 5);
        $this->assertSame($beforeA + $dailyLimit * $days, $this->headroomFor($owner, $a)['headroom_cg']);
        $this->assertSame($beforeB, $this->headroomFor($owner, $b)['headroom_cg']); // B untouched
    }
}
