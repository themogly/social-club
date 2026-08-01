<?php

namespace Tests\Feature\Till;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Dispensing\VoidDispensation;
use App\Actions\Till\CloseTill;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Period;
use App\Support\ZReport;
use App\ViewModels\Reports\TillReport;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 103 — a closed till's Z-report must report the figures it was CLOSED with, not a live recomputation
 * that a later void silently rewrites. A CLOSED session reads its stored expected_cents; an OPEN session still
 * derives live. counted − expected === variance is an invariant on every closed session and on the totals row.
 */
class FrozenZReportTest extends TestCase
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
            'price_per_gram_cents' => 1000, 'active' => true, // €10,00/g
        ]);
        $this->batch = Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id,
            'location_id' => $this->location->id, 'remaining_cg' => 100000, 'status' => BatchStatus::OPEN,
        ]);
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::MANAGER->value); // till.open, till.close, dispensation.void, pos.use
        $user->locations()->sync([$this->location->id]);

        return $user;
    }

    private function member(): Member
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'carencia_ends_at' => now()->subDay()]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
        ]);

        return $member;
    }

    private function cashDispensation(Member $member, TillSession $till, User $operator, int $gramsCg, int $cashCents): Dispensation
    {
        return (new CommitDispensation)->handle(
            $member, $this->location,
            [['genetic_id' => $this->genetic->id, 'batch_id' => $this->batch->id, 'grams_cg' => $gramsCg]],
            ['till_session_id' => $till->id, 'cash_cents' => $cashCents, 'operator_id' => $operator->id],
        );
    }

    public function test_a_closed_session_reports_its_stored_expected_not_a_recomputation(): void
    {
        $manager = $this->manager();
        $till = (new OpenTill)->handle($this->location, 'POS-1', 10000); // €100 float
        $this->cashDispensation($this->member(), $till, $manager, 350, 3500); // €35 cash → expected €135

        (new CloseTill)->handle($till, 13500, $manager); // counted €135, clean

        $z = ZReport::for($till->fresh());
        $this->assertSame(13500, $z['expected']);
        $this->assertSame($till->fresh()->expected_cents->cents, $z['expected']); // it IS the stored figure
        $this->assertSame(13500 - 13500, $z['variance']);
        $this->assertFalse($z['post_close_adjusted']);
    }

    public function test_a_post_close_void_does_not_change_the_signed_cash_up(): void
    {
        // The regression that found this: clean at close, then a next-day void of a completed dispensation.
        $manager = $this->manager();
        $till = (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $dispensation = $this->cashDispensation($this->member(), $till, $manager, 350, 3500);

        (new CloseTill)->handle($till, 13500, $manager);
        $signed = ZReport::for($till->fresh());
        $this->assertSame(13500, $signed['expected']);
        $this->assertSame(0, $signed['variance']);

        (new VoidDispensation)->handle($dispensation, $manager, 'wrong weight, corrected next day');

        $reprinted = ZReport::for($till->fresh());
        // The three signed figures are UNCHANGED — the cash-up cannot be rewritten after the fact.
        $this->assertSame(13500, $reprinted['expected']);
        $this->assertSame(13500, $reprinted['counted']);
        $this->assertSame(0, $reprinted['variance']);
        $this->assertSame($reprinted['counted'] - $reprinted['expected'], $reprinted['variance']);
        // …but the amendment is surfaced, not silent.
        $this->assertTrue($reprinted['post_close_adjusted']);
        $this->assertSame(10000, $reprinted['expected_live']); // live recomputation dropped the voided €35
    }

    public function test_an_open_session_expected_still_tracks_the_ledger_live(): void
    {
        $manager = $this->manager();
        $till = (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $this->assertSame(10000, ZReport::for($till)['expected']); // just the float

        $this->cashDispensation($this->member(), $till, $manager, 350, 3500);

        $this->assertSame(13500, ZReport::for($till->fresh())['expected']); // moved with the sale
        $this->assertFalse(ZReport::for($till->fresh())['post_close_adjusted']);
    }

    public function test_the_till_report_totals_are_internally_consistent_over_a_mixed_period(): void
    {
        $manager = $this->manager();

        // A clean closed session.
        $a = (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $this->cashDispensation($this->member(), $a, $manager, 350, 3500);
        (new CloseTill)->handle($a, 13500, $manager);

        // A closed session then voided (post-close correction) — figures must stay frozen.
        $b = (new OpenTill)->handle($this->location, 'POS-2', 10000);
        $d = $this->cashDispensation($this->member(), $b, $manager, 350, 3500);
        (new CloseTill)->handle($b, 13480, $manager); // €0,20 short
        (new VoidDispensation)->handle($d, $manager, 'next-day correction');

        // An open session (no counted/variance yet).
        (new OpenTill)->handle($this->location, 'POS-3', 10000);

        $report = new TillReport($this->org->id, [$this->location->id], Period::thisMonth());
        $sessions = $report->tables()[0];

        // Every CLOSED row satisfies the invariant.
        foreach ($sessions->rows as $row) {
            if ($row['counted'] === null) {
                continue; // open
            }
            $this->assertSame((int) $row['counted'] - (int) $row['expected'], (int) $row['variance'], 'row invariant');
        }

        // The totals row satisfies it too: Σcounted − Σexpected === Σvariance.
        $t = $sessions->totals;
        $this->assertSame((int) $t['counted'] - (int) $t['expected'], (int) $t['variance']);
    }
}
