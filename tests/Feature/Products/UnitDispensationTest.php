<?php

namespace Tests\Feature\Products;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Dispensing\ResolveMemberLimits;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Exceptions\LimitExceededException;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\StockMovement;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dispensing a UNIT product through CommitDispensation (THE compliance boundary):
 * units are stored, grams_cg is COMPUTED and stored on the line, the per-unit rate is
 * frozen, and the daily/monthly ceiling blocks IDENTICALLY to an equivalent weight
 * dispensation — with ResolveMemberLimits itself unchanged.
 */
class UnitDispensationTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $preroll;

    private Genetic $flower;

    private Batch $unitBatch;

    private Batch $weightBatch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);

        $this->preroll = Genetic::factory()->preroll(70)->create(['organisation_id' => $this->org->id]);
        GeneticPrice::factory()->perUnit(800)->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->preroll->id, 'location_id' => $this->location->id,
        ]);
        $this->unitBatch = Batch::factory()->units(100)->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->preroll->id, 'location_id' => $this->location->id,
        ]);

        $this->flower = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->flower->id,
            'location_id' => $this->location->id, 'price_per_gram_cents' => 1000,
        ]);
        $this->weightBatch = Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->flower->id,
            'location_id' => $this->location->id, 'remaining_cg' => 100000, 'status' => BatchStatus::OPEN,
        ]);
    }

    private function member(int $daily = 100000, int $monthly = 100000): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'carencia_ends_at' => now()->subDay(),
            'daily_limit_cg' => $daily, 'monthly_limit_cg' => $monthly,
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
        ]);

        return $member;
    }

    public function test_dispensing_prerolls_stores_units_computed_grams_frozen_price_and_total(): void
    {
        $member = $this->member();

        $dispensation = (new CommitDispensation)->handle($member, $this->location, [
            ['genetic_id' => $this->preroll->id, 'batch_id' => $this->unitBatch->id, 'units' => 3],
        ]);

        $line = $dispensation->lines->first();
        $this->assertSame(3, $line->units_dispensed);
        $this->assertSame(210, $line->grams_cg->centigrams);   // 3 × 70 cg — computed + stored
        $this->assertSame(800, $line->price_per_unit_cents);   // frozen per-unit rate
        $this->assertNull($line->price_per_gram_cents);
        $this->assertSame(2400, $line->line_total_cents->cents); // 3 × 800
        $this->assertSame(2400, $dispensation->total_cents->cents);

        // Stock moved in whole units through the single writer.
        $this->assertSame(97, $this->unitBatch->fresh()->remaining_units);
        $movement = StockMovement::query()->withoutGlobalScopes()
            ->where('stockable_id', $this->unitBatch->id)->where('type', 'DISPENSE')->first();
        $this->assertSame(-3, $movement->qty_units);
        $this->assertNull($movement->qty_cg);
    }

    public function test_the_daily_ceiling_blocks_a_unit_dispensation_at_the_same_grams_as_an_equivalent_weight_one(): void
    {
        // UNIT member: 5 prerolls = 350 cg exactly (= limit) OK; a 6th (would be 420) is blocked.
        $unitMember = $this->member(daily: 350);
        (new CommitDispensation)->handle($unitMember, $this->location, [
            ['genetic_id' => $this->preroll->id, 'batch_id' => $this->unitBatch->id, 'units' => 5],
        ]);
        try {
            (new CommitDispensation)->handle($unitMember, $this->location, [
                ['genetic_id' => $this->preroll->id, 'batch_id' => $this->unitBatch->id, 'units' => 1],
            ]);
            $this->fail('The 6th preroll (420 cg > 350) must breach the daily limit.');
        } catch (LimitExceededException) {
            $this->assertTrue(true);
        }

        // WEIGHT member: 3.50 g = 350 cg OK; +0.20 g (would be 370) is blocked.
        $weightMember = $this->member(daily: 350);
        (new CommitDispensation)->handle($weightMember, $this->location, [
            ['genetic_id' => $this->flower->id, 'batch_id' => $this->weightBatch->id, 'grams_cg' => 350],
        ]);
        try {
            (new CommitDispensation)->handle($weightMember, $this->location, [
                ['genetic_id' => $this->flower->id, 'batch_id' => $this->weightBatch->id, 'grams_cg' => 20],
            ]);
            $this->fail('The extra 0.20 g (370 cg > 350) must breach the daily limit.');
        } catch (LimitExceededException) {
            $this->assertTrue(true);
        }

        // Both reached the same grams_cg used — the ceiling reads one figure, not two.
        $unitUsed = (new ResolveMemberLimits)->handle($unitMember, $this->location)->dailyUsedCg;
        $weightUsed = (new ResolveMemberLimits)->handle($weightMember, $this->location)->dailyUsedCg;
        $this->assertSame(350, $unitUsed);
        $this->assertSame($unitUsed, $weightUsed);
    }

    public function test_resolve_member_limits_does_not_branch_on_product_type(): void
    {
        // The load-bearing guarantee: only what FEEDS grams_cg changed, never the limit arithmetic.
        $source = (string) file_get_contents(app_path('Actions/Dispensing/ResolveMemberLimits.php'));

        foreach (['units_dispensed', 'UnitType', 'ProductType', 'product_type', 'grams_per_unit', 'remaining_units'] as $token) {
            $this->assertStringNotContainsString($token, $source, "ResolveMemberLimits must not reference {$token}.");
        }
    }
}
