<?php

namespace Tests\Feature\Products;

use App\Actions\Pricing\ResolvePrice;
use App\Enums\MembershipStatus;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Support\ActiveScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ResolvePrice is THE one resolver for both kinds — same tier logic, different column:
 * per gram for a WEIGHT genetic, per unit for a UNIT genetic. lineFor()/lineForUnits()
 * compute the line from the resolved rate.
 */
class UnitPricingTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Member $member;

    private MembershipTier $tier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        $this->member = Member::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $this->member->id,
            'location_id' => $this->location->id, 'tier_id' => $this->tier->id, 'status' => MembershipStatus::ACTIVE,
        ]);
    }

    public function test_a_weight_genetic_resolves_a_per_gram_rate(): void
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id,
            'location_id' => $this->location->id, 'price_per_gram_cents' => 900,
        ]);

        $result = (new ResolvePrice)->forGenetic($genetic, $this->location, $this->member);
        $this->assertFalse($result->perUnit);
        $this->assertSame(900, $result->ratePerGramCents);
        // 3.50 g → 900 × 350 / 100 = 3150.
        $this->assertSame(3150, $result->lineFor(350)['total_cents']);
    }

    public function test_a_unit_genetic_resolves_a_per_unit_rate(): void
    {
        $genetic = Genetic::factory()->preroll(70)->create(['organisation_id' => $this->org->id]);
        GeneticPrice::factory()->perUnit(800)->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id,
            'location_id' => $this->location->id,
        ]);

        $result = (new ResolvePrice)->forGenetic($genetic, $this->location, $this->member);
        $this->assertTrue($result->perUnit);
        $this->assertSame(800, $result->ratePerGramCents);
        // 3 units × 800 = 2400 (no division — whole units).
        $this->assertSame(2400, $result->lineForUnits(3)['total_cents']);
    }

    public function test_the_tier_rate_beats_the_base_rate_for_each_kind(): void
    {
        // WEIGHT: base 900, tier 500.
        $weight = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $weight->id,
            'location_id' => $this->location->id, 'price_per_gram_cents' => 900,
        ]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $weight->id,
            'location_id' => $this->location->id, 'tier_id' => $this->tier->id, 'price_per_gram_cents' => 500,
        ]);
        $this->assertSame(500, (new ResolvePrice)->forGenetic($weight, $this->location, $this->member)->ratePerGramCents);

        // UNIT: base 800, tier 600.
        $unit = Genetic::factory()->preroll(70)->create(['organisation_id' => $this->org->id]);
        GeneticPrice::factory()->perUnit(800)->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $unit->id, 'location_id' => $this->location->id,
        ]);
        GeneticPrice::factory()->perUnit(600)->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $unit->id,
            'location_id' => $this->location->id, 'tier_id' => $this->tier->id,
        ]);
        $result = (new ResolvePrice)->forGenetic($unit, $this->location, $this->member);
        $this->assertTrue($result->perUnit);
        $this->assertSame(600, $result->ratePerGramCents);
    }
}
