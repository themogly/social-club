<?php

namespace Tests\Feature\Stock;

use App\Actions\Stock\IntakeBatch;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Exceptions\StockCeilingExceededException;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Settings;
use App\Support\StockCeiling;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 110 — the legal stock ceiling. The single figure that separates a compliant CSC from a trafficking
 * case, so its arithmetic must be per-sede (active membership AT the location, not the whole association) and
 * it must actually enforce at intake, not just colour a chart.
 */
class StockCeilingTest extends TestCase
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

    private function location(): Location
    {
        return Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function memberAt(Location $location, MembershipStatus $membership = MembershipStatus::ACTIVE, MemberStatus $status = MemberStatus::ACTIVE): Member
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => $status]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id,
            'location_id' => $location->id, 'tier_id' => $tier->id, 'status' => $membership,
        ]);

        return $member;
    }

    public function test_two_sedes_with_different_membership_counts_get_different_ceilings(): void
    {
        // THE bug: the ceiling used to count the whole association against each sede's stock.
        $a = $this->location();
        $b = $this->location();
        $this->memberAt($a);
        $this->memberAt($a); // 2 active memberships at A
        $this->memberAt($b); // 1 active membership at B

        // ceiling = active members × daily_limit (350cg) × ceiling_days (5)
        $this->assertSame(2 * 350 * 5, StockCeiling::forLocation($a)['ceiling_cg']);
        $this->assertSame(1 * 350 * 5, StockCeiling::forLocation($b)['ceiling_cg']);
        $this->assertNotSame(StockCeiling::forLocation($a)['ceiling_cg'], StockCeiling::forLocation($b)['ceiling_cg']);
    }

    public function test_a_member_active_at_one_sede_counts_only_there(): void
    {
        $a = $this->location();
        $b = $this->location();
        $this->memberAt($a); // active at A only

        $this->assertSame(1, StockCeiling::forLocation($a)['active_members']);
        $this->assertSame(0, StockCeiling::forLocation($b)['active_members']);
    }

    public function test_an_expelled_or_lapsed_member_does_not_raise_the_ceiling(): void
    {
        $location = $this->location();
        $this->memberAt($location, MembershipStatus::LAPSED);                       // lapsed membership
        $this->memberAt($location, MembershipStatus::ACTIVE, MemberStatus::EXPELLED); // expelled member

        $this->assertSame(0, StockCeiling::forLocation($location)['active_members']);
    }

    private function genetic(): Genetic
    {
        return Genetic::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function fillToNearCeiling(Location $location, Genetic $genetic, int $cg): void
    {
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id,
            'location_id' => $location->id, 'remaining_cg' => $cg, 'status' => BatchStatus::OPEN,
        ]);
    }

    public function test_intake_over_the_ceiling_warns_by_default_and_proceeds(): void
    {
        $location = $this->location();
        $this->memberAt($location); // ceiling = 1750 cg
        $genetic = $this->genetic();
        $this->fillToNearCeiling($location, $genetic, 1500);

        // +5,00 g → 2000 cg projected, over the 1750 ceiling. Default WARN → the batch is still created.
        $batch = (new IntakeBatch)->handle($genetic, $location, ['grams' => 5]);
        $this->assertSame(500, $batch->remaining_cg->centigrams);
        $this->assertTrue(StockCeiling::forLocation($location)['exceeded']);
    }

    public function test_intake_over_the_ceiling_blocks_when_the_club_sets_block(): void
    {
        Settings::set('enforcement', $this->blockCeiling());
        $location = $this->location();
        $this->memberAt($location);
        $genetic = $this->genetic();
        $this->fillToNearCeiling($location, $genetic, 1500);

        $this->expectException(StockCeilingExceededException::class);
        (new IntakeBatch)->handle($genetic, $location, ['grams' => 5]);
    }

    public function test_the_ceiling_override_is_refused_without_the_permission_and_recorded_with_it(): void
    {
        Settings::set('enforcement', $this->blockCeiling());
        $location = $this->location();
        $this->memberAt($location);
        $genetic = $this->genetic();
        $this->fillToNearCeiling($location, $genetic, 1500);

        // STAFF holds no limits.override → refused even with a reason.
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);
        try {
            (new IntakeBatch)->handle($genetic, $location, ['grams' => 5, 'override' => true, 'override_by' => $staff, 'override_reason' => 'harvest']);
            $this->fail('Expected an AuthorizationException.');
        } catch (AuthorizationException) {
            // expected
        }

        // MANAGER holds limits.override → the intake proceeds and the override is audited.
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $batch = (new IntakeBatch)->handle($genetic, $location, ['grams' => 5, 'override' => true, 'override_by' => $manager, 'override_reason' => 'Cosecha estacional']);
        $this->assertSame(500, $batch->remaining_cg->centigrams);
        $this->assertDatabaseHas('audit_logs', ['action' => 'stock.ceiling.overridden']);
    }

    public function test_the_dashboard_exceeded_flag_agrees_with_what_intake_measures(): void
    {
        $location = $this->location();
        $this->memberAt($location); // ceiling 1750
        $genetic = $this->genetic();
        $this->fillToNearCeiling($location, $genetic, 2000); // already over

        // One figure: the exceeded flag the dashboard reads is the same StockCeiling intake enforces against.
        $this->assertTrue(StockCeiling::forLocation($location)['exceeded']);
    }

    /** @return array<string, mixed> The enforcement matrix with the stock ceiling set to BLOCK. */
    private function blockCeiling(): array
    {
        $matrix = Settings::get('enforcement', Settings::DEFAULTS['enforcement']);
        $matrix['stock']['ceiling'] = 'BLOCK';

        return $matrix;
    }
}
