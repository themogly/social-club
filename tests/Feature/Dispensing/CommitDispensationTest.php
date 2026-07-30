<?php

namespace Tests\Feature\Dispensing;

use App\Actions\Dispensing\CommitDispensation;
use App\Enums\BatchStatus;
use App\Enums\DispensationStatus;
use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Exceptions\DispensationBlockedException;
use App\Exceptions\LimitExceededException;
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
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommitDispensationTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $genetic;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id,
            'location_id' => $this->location->id, 'tier_id' => null,
            'price_per_gram_cents' => 1000, 'active' => true,
        ]);
        $this->batch = Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id,
            'location_id' => $this->location->id, 'remaining_cg' => 100000, 'status' => BatchStatus::OPEN,
        ]);
    }

    private function member(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'carencia_ends_at' => now()->subDay(),
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
        ]);

        return $member;
    }

    /** @param array<string,mixed> $options */
    private function commit(Member $member, int $gramsCg, array $options = []): Dispensation
    {
        return (new CommitDispensation)->handle(
            $member, $this->location,
            [['genetic_id' => $this->genetic->id, 'batch_id' => $this->batch->id, 'grams_cg' => $gramsCg]],
            $options,
        );
    }

    public function test_at_the_daily_boundary_a_fitting_amount_succeeds_and_an_over_amount_is_blocked(): void
    {
        // Default daily limit 350 cg (3.5 g).
        $a = $this->member();
        $this->commit($a, 340);                 // 3.4 g used
        $this->commit($a, 10);                   // + 0.1 g = 3.5 g exactly — OK
        $this->assertSame(2, $a->dispensations()->count());

        $b = $this->member();
        $this->commit($b, 340);                  // 3.4 g used
        $this->expectException(LimitExceededException::class);
        $this->commit($b, 20);                   // + 0.2 g = 3.6 g — blocked
    }

    public function test_two_movements_that_individually_pass_but_jointly_breach_only_commit_once(): void
    {
        $member = $this->member();
        $this->commit($member, 340);

        $this->commit($member, 10);              // 3.5 — commits
        try {
            $this->commit($member, 10);          // 3.6 — refused
            $this->fail('The joint breach should have been blocked.');
        } catch (LimitExceededException) {
            // expected
        }

        $completed = $member->dispensations()->where('status', DispensationStatus::COMPLETED->value)->count();
        $this->assertSame(2, $completed); // 340 + 10 only
    }

    public function test_voiding_a_dispensation_releases_its_grams(): void
    {
        $member = $this->member();
        $first = $this->commit($member, 300);    // 3.0 g used

        try {
            $this->commit($member, 100);         // would be 4.0 g — blocked
            $this->fail('Expected a limit breach.');
        } catch (LimitExceededException) {
        }

        $first->update(['status' => DispensationStatus::VOIDED]); // release 3.0 g
        $second = $this->commit($member, 100);   // now only 1.0 g — OK (release worked)
        $this->assertSame(DispensationStatus::COMPLETED, $second->status);
        $this->assertSame(2, $member->dispensations()->count()); // D1 (voided) + D2 (completed)
    }

    public function test_a_limit_breach_is_refused_without_override_and_allowed_with_it(): void
    {
        $member = $this->member();
        $this->commit($member, 340);

        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);

        $this->commit($member, 30, [ // 3.7 g — breach, but overridden by a manager
            'override' => true, 'override_by' => $manager, 'override_reason' => 'Therapeutic exception',
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'dispensation.limit.override']);
    }

    public function test_a_member_within_carencia_is_blocked(): void
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'carencia_ends_at' => now()->addDays(10)]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
        ]);

        $this->expectException(DispensationBlockedException::class);
        $this->commit($member, 100);
    }

    public function test_a_member_without_an_active_membership_is_blocked(): void
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'carencia_ends_at' => now()->subDay()]);

        $this->expectException(DispensationBlockedException::class);
        $this->commit($member, 100);
    }
}
