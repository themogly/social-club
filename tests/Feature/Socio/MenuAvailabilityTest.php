<?php

namespace Tests\Feature\Socio;

use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\ProductType;
use App\Enums\SettingType;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Prompt 185 — the member menu showed a price for something that might not be there.
 *
 * A member checked the menu, travelled in for a strain, and found it had run out. The data to prevent that
 * already existed (`lowStockThresholdCg`, per-sede falling back to the org setting) and was used only
 * internally.
 *
 * What is shown is a STATE, never a quantity. The assertion that matters most is the negative one: a member
 * viewing source must not be able to recover the club's holdings — a gram count of cannabis held at a named
 * address is not something a Spanish asociación publishes, and a precise figure invites a race to the
 * counter.
 */
class MenuAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $a;

    private Location $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->a = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->b = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function memberAt(Location $location): Member
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
        ]);

        return $member;
    }

    /**
     * A priced genetic at a sede.
     *
     * `low_stock_threshold_cg` is pinned to NULL deliberately: GeneticPriceFactory otherwise seeds a RANDOM
     * threshold between 1000 and 10000cg, which would make every assertion in this file depend on a dice
     * roll. Null is also the case that matters — it is what makes the ORG default apply, which is the
     * fallback this branch reuses.
     *
     * A per-unit product needs its grams-per-unit at CREATION (GeneticObserver refuses otherwise), so it
     * goes in with the attributes rather than being patched on afterwards.
     */
    private function priced(string $name, Location $loc, ?ProductType $type = null): Genetic
    {
        $isUnit = $type !== null && $type !== ProductType::FLOWER;

        $genetic = Genetic::factory()->create([
            'organisation_id' => $this->org->id, 'name' => $name, 'active' => true, 'published' => true,
            'product_type' => $type ?? ProductType::FLOWER,
        ] + ($isUnit ? ['grams_per_unit_cg' => 100] : []));

        // A per-unit genetic prices per UNIT and leaves the per-gram column null — the model's own invariant.
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $loc->id,
            'tier_id' => null, 'active' => true, 'low_stock_threshold_cg' => null,
        ] + ($isUnit
            ? ['price_per_unit_cents' => 500, 'price_per_gram_cents' => null]
            : ['price_per_gram_cents' => 1000, 'price_per_unit_cents' => null]));

        return $genetic;
    }

    /**
     * A batch, respecting the model's own invariant: a by-weight batch sets the cg columns and leaves the
     * unit columns null, a per-unit batch does the reverse. Going through the factory rather than hand-
     * building a row, so this cannot drift from what the real writer produces.
     */
    private function stock(Genetic $g, Location $loc, ?int $cg = null, ?int $units = null): Batch
    {
        $attributes = [
            'organisation_id' => $this->org->id, 'genetic_id' => $g->id, 'location_id' => $loc->id,
            'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(),
        ];

        $attributes += $units !== null
            ? ['initial_units' => max($units, 1), 'remaining_units' => $units, 'initial_cg' => null, 'remaining_cg' => null]
            : ['initial_cg' => max($cg ?? 0, 1), 'remaining_cg' => $cg ?? 0, 'initial_units' => null, 'remaining_units' => null];

        return Batch::factory()->create($attributes);
    }

    private function menu(Member $member): TestResponse
    {
        return $this->actingAs($member, 'member')->get(route('socio.menu'));
    }

    // --- the three states ------------------------------------------------------------------------------

    public function test_plenty_under_the_threshold_and_zero_each_render_their_state(): void
    {
        $member = $this->memberAt($this->a);

        $plenty = $this->priced('Amnesia Haze', $this->a);
        $this->stock($plenty, $this->a, cg: 50000);          // well over the 5000cg default

        $low = $this->priced('Critical Kush', $this->a);
        $this->stock($low, $this->a, cg: 1000);              // under it

        $none = $this->priced('Lemon Skunk', $this->a);
        $this->stock($none, $this->a, cg: 0);

        $this->assertSame(Genetic::AVAILABLE, $plenty->availabilityAt($this->a->id));
        $this->assertSame(Genetic::LOW, $low->availabilityAt($this->a->id));
        $this->assertSame(Genetic::UNAVAILABLE, $none->availabilityAt($this->a->id));

        $response = $this->menu($member);
        $response->assertOk();
        $response->assertSee('data-availability="available"', false);
        $response->assertSee('data-availability="low"', false);
        $response->assertSee('data-availability="unavailable"', false);
        $response->assertSee(__('Disponible'));
        $response->assertSee(__('Quedan pocas'));
        $response->assertSee(__('Sin existencias'));
    }

    /** The assertion the branch exists for: a member must not be able to recover the club's holdings. */
    public function test_no_gram_figure_appears_anywhere_in_the_response(): void
    {
        $member = $this->memberAt($this->a);
        $g = $this->priced('Amnesia Haze', $this->a);
        $this->stock($g, $this->a, cg: 47350); // a distinctive figure, in every form it could leak as

        $body = $this->menu($member)->getContent();

        foreach (['47350', '473.5', '473,5', 'remaining_cg', 'remaining_units', 'onHand', 'stock'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "the menu leaks holdings via \"$leak\"");
        }
    }

    // --- per sede, not per club ------------------------------------------------------------------------

    public function test_the_same_genetic_reads_differently_at_two_sedes(): void
    {
        $genetic = $this->priced('Blue Dream', $this->a);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->b->id,
            'tier_id' => null, 'price_per_gram_cents' => 1000, 'active' => true,
        ]);

        $this->stock($genetic, $this->a, cg: 50000);  // plenty at A
        $this->stock($genetic, $this->b, cg: 0);      // empty at B

        // A member walking into B must not be told it is available because A has some.
        $this->assertSame(Genetic::AVAILABLE, $genetic->availabilityAt($this->a->id));
        $this->assertSame(Genetic::UNAVAILABLE, $genetic->availabilityAt($this->b->id));

        $this->menu($this->memberAt($this->a))->assertSee('data-availability="available"', false);
        $this->menu($this->memberAt($this->b))->assertSee('data-availability="unavailable"', false);
    }

    // --- the threshold, per sede then org ---------------------------------------------------------------

    public function test_a_per_sede_threshold_overrides_the_org_default(): void
    {
        $genetic = $this->priced('Gorilla Glue', $this->a);
        $this->stock($genetic, $this->a, cg: 6000); // above the 5000 org default

        $this->assertSame(Genetic::AVAILABLE, $genetic->availabilityAt($this->a->id));

        // Raise THIS sede's threshold above the holding — the same stock now reads as low. (The factory's
        // random threshold is pinned to null in priced(), so this is the only per-sede value in play.)
        $genetic->prices()->withoutGlobalScopes()
            ->where('location_id', $this->a->id)->whereNull('tier_id')
            ->update(['low_stock_threshold_cg' => 10000]);

        $this->assertSame(Genetic::LOW, $genetic->fresh()->availabilityAt($this->a->id));
    }

    public function test_with_no_per_sede_row_the_org_default_applies(): void
    {
        $genetic = $this->priced('White Widow', $this->a);
        $this->stock($genetic, $this->a, cg: 3000);

        // No per-price threshold set → the org setting (default 5000) → 3000 is low.
        $this->assertSame(Genetic::LOW, $genetic->availabilityAt($this->a->id));

        Settings::set('low_stock_threshold_cg', 1000, SettingType::INT);
        $this->assertSame(Genetic::AVAILABLE, $genetic->fresh()->availabilityAt($this->a->id));
    }

    // --- an unavailable genetic stays on the menu --------------------------------------------------------

    public function test_an_unavailable_genetic_still_appears(): void
    {
        $member = $this->memberAt($this->a);
        $none = $this->priced('Sour Diesel', $this->a);
        $this->stock($none, $this->a, cg: 0);

        // Disappearing teaches a member nothing and reads as the club having stopped carrying it.
        $this->menu($member)->assertOk()->assertSee('Sour Diesel')->assertSee(__('Sin existencias'));
    }

    // --- unit genetics ------------------------------------------------------------------------------------

    public function test_a_unit_genetic_reports_its_gram_equivalent_not_its_unit_count(): void
    {
        $preroll = $this->priced('Preroll', $this->a, ProductType::PREROLL); // 1 g per unit

        // 80 units × 1 g = 8000 cg, over the 5000 default.
        $this->stock($preroll->fresh(), $this->a, units: 80);
        $this->assertSame(Genetic::AVAILABLE, $preroll->fresh()->availabilityAt($this->a->id));

        // 30 units × 1 g = 3000 cg, under it.
        Batch::query()->withoutGlobalScopes()->where('genetic_id', $preroll->id)->update(['remaining_units' => 30]);
        $this->assertSame(Genetic::LOW, $preroll->fresh()->availabilityAt($this->a->id));

        Batch::query()->withoutGlobalScopes()->where('genetic_id', $preroll->id)->update(['remaining_units' => 0]);
        $this->assertSame(Genetic::UNAVAILABLE, $preroll->fresh()->availabilityAt($this->a->id));
    }

    // --- an expired batch is not stock ---------------------------------------------------------------------

    public function test_an_expired_batch_does_not_count_as_available(): void
    {
        $genetic = $this->priced('Old Stock', $this->a);
        $batch = $this->stock($genetic, $this->a, cg: 50000);
        $batch->update(['expires_on' => now()->subDay()]);

        // SelectBatch refuses expired batches, so counting them would let the menu promise something the
        // counter would then refuse — the exact failure this branch exists to prevent.
        $this->assertSame(Genetic::UNAVAILABLE, $genetic->fresh()->availabilityAt($this->a->id));
    }

    // --- nothing else moved ----------------------------------------------------------------------------------

    public function test_the_menu_still_renders_the_price_exactly_as_before(): void
    {
        $member = $this->memberAt($this->a);
        $genetic = $this->priced('Amnesia Haze', $this->a);
        $this->stock($genetic, $this->a, cg: 50000);

        $this->menu($member)->assertOk()->assertSee('/g', false)->assertSee('Amnesia Haze');
    }
}
