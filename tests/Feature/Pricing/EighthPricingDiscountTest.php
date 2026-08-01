<?php

namespace Tests\Feature\Pricing;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Pricing\ResolvePrice;
use App\Enums\BatchStatus;
use App\Enums\DiscountMode;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Filament\Resources\Genetics\Pages\EditGenetic;
use App\Filament\Resources\Genetics\RelationManagers\GeneticPricesRelationManager;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberDiscount;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\PriceResult;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 90 — the eighth "discount" must never charge MORE than per-gram, and must not eat a member's
 * discount. Two guarantees, asserted to the cent: (1) the break is floored at the per-gram total, and
 * (2) the member's discount applies ON TOP of the eighth price — the same chosen discount as the per-gram
 * rate. Exactness (line totals sum to the group charge) holds at every multiple and with a discount.
 */
class EighthPricingDiscountTest extends TestCase
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

    // --- Unit-level: the arithmetic in applyEighthBreaks, effective figures in / cents out ----------------

    /** @param list<array{grams_cg: int, rate_cents: int, per_gram_total: int, eighth_price: ?int}> $lines */
    private function breaks(array $lines): array
    {
        return (new ResolvePrice)->applyEighthBreaks($lines);
    }

    public function test_an_eighth_above_per_gram_never_increases_the_charge_single_strain(): void
    {
        // Per-gram €10 → 3.5 g costs 3500; a badly-set eighth of 4000 must NOT be charged.
        $out = $this->breaks([['grams_cg' => 350, 'rate_cents' => 1000, 'per_gram_total' => 3500, 'eighth_price' => 4000]]);

        $this->assertSame(3500, $out[0]['total_cents']);
        $this->assertFalse($out[0]['eighth_applied']);
    }

    public function test_an_eighth_above_per_gram_never_increases_the_charge_split_across_two(): void
    {
        $out = $this->breaks([
            ['grams_cg' => 175, 'rate_cents' => 1000, 'per_gram_total' => 1750, 'eighth_price' => 4000],
            ['grams_cg' => 175, 'rate_cents' => 1000, 'per_gram_total' => 1750, 'eighth_price' => 4000],
        ]);

        $this->assertSame(3500, $out[0]['total_cents'] + $out[1]['total_cents']); // per-gram, not the 4000 eighth
        $this->assertSame([1750, 1750], [$out[0]['total_cents'], $out[1]['total_cents']]);
    }

    public function test_the_discounted_eighth_is_charged_single_strain(): void
    {
        // 30% off: effective rate 700, effective eighth 1750; 3.5 g per-gram = 2450 → the eighth (1750) wins.
        $out = $this->breaks([['grams_cg' => 350, 'rate_cents' => 700, 'per_gram_total' => 2450, 'eighth_price' => 1750]]);

        $this->assertSame(1750, $out[0]['total_cents']);
        $this->assertTrue($out[0]['eighth_applied']);
    }

    public function test_the_discounted_eighth_is_charged_split_across_two(): void
    {
        $out = $this->breaks([
            ['grams_cg' => 175, 'rate_cents' => 700, 'per_gram_total' => 1225, 'eighth_price' => 1750],
            ['grams_cg' => 175, 'rate_cents' => 700, 'per_gram_total' => 1225, 'eighth_price' => 1750],
        ]);

        $this->assertSame([875, 875], [$out[0]['total_cents'], $out[1]['total_cents']]);
        $this->assertSame(1750, $out[0]['total_cents'] + $out[1]['total_cents']);
    }

    public function test_multiples_carry_the_discount(): void
    {
        // Effective eighth 1750, effective rate 700/g.
        $sevenG = $this->breaks([['grams_cg' => 700, 'rate_cents' => 700, 'per_gram_total' => 4900, 'eighth_price' => 1750]]);
        $this->assertSame(3500, $sevenG[0]['total_cents']); // 2 × 1750

        $tenHalf = $this->breaks([['grams_cg' => 1050, 'rate_cents' => 700, 'per_gram_total' => 7350, 'eighth_price' => 1750]]);
        $this->assertSame(5250, $tenHalf[0]['total_cents']); // 3 × 1750

        // 8 g = two eighths + 1 g at the effective per-gram rate: 2 × 1750 + 700 = 4200.
        $eightG = $this->breaks([['grams_cg' => 800, 'rate_cents' => 700, 'per_gram_total' => 5600, 'eighth_price' => 1750]]);
        $this->assertSame(4200, $eightG[0]['total_cents']);
    }

    public function test_the_exact_sum_property_holds_with_a_discount_over_an_uneven_three_line_split(): void
    {
        // Effective eighth 2400 (a value that does NOT divide evenly by the weights) across 117+117+116 cg.
        $out = $this->breaks([
            ['grams_cg' => 117, 'rate_cents' => 700, 'per_gram_total' => 819, 'eighth_price' => 2400],
            ['grams_cg' => 117, 'rate_cents' => 700, 'per_gram_total' => 819, 'eighth_price' => 2400],
            ['grams_cg' => 116, 'rate_cents' => 700, 'per_gram_total' => 812, 'eighth_price' => 2400],
        ]);

        $sum = $out[0]['total_cents'] + $out[1]['total_cents'] + $out[2]['total_cents'];
        $this->assertSame(2400, $sum); // no cent lost or gained
        $this->assertSame([802, 802, 796], [$out[0]['total_cents'], $out[1]['total_cents'], $out[2]['total_cents']]);
    }

    // --- PriceResult: the discount composes onto the eighth via the SAME chosen discount -----------------

    public function test_effective_eighth_applies_the_same_discount_as_the_per_gram_rate(): void
    {
        $discounted = new PriceResult(1000, null, ['mode' => DiscountMode::PERCENT, 'value_bp' => 3000, 'value_cents' => null, 'label' => 'Terapéutico'], false, 2500);

        // ONE discount object drives both — never two independently resolved.
        $this->assertSame(700, $discounted->effectiveRatePerGramCents());
        $this->assertSame(1750, $discounted->effectiveEighthPriceCents()); // 2500 − 30%

        $noEighth = new PriceResult(1000, null, ['mode' => DiscountMode::PERCENT, 'value_bp' => 3000, 'value_cents' => null, 'label' => 'x'], false, null);
        $this->assertNull($noEighth->effectiveEighthPriceCents());
    }

    // --- End to end: a real discounted member through CommitDispensation ---------------------------------

    /** @return array{0: Genetic, 1: Batch} */
    private function strain(int $perGram, ?int $eighthCents): array
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'tier_id' => null, 'price_per_gram_cents' => $perGram, 'price_per_eighth_cents' => $eighthCents, 'active' => true,
        ]);
        $batch = Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'remaining_cg' => 100000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(),
        ]);

        return [$genetic, $batch];
    }

    private function member(bool $withThirtyPercentOff = false): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(),
            'daily_limit_cg' => 100000, 'monthly_limit_cg' => 1000000,
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);
        if ($withThirtyPercentOff) {
            MemberDiscount::create([
                'member_id' => $member->id, 'discount_id' => null,
                'mode' => DiscountMode::PERCENT, 'value_bp' => 3000, 'value_cents' => null,
            ]);
        }

        return $member;
    }

    /** @param list<array{genetic_id: string, batch_id: string, grams_cg: int}> $lines */
    private function commit(Member $member, array $lines): Dispensation
    {
        return (new CommitDispensation)->handle($member, $this->location, $lines);
    }

    public function test_a_discounted_member_buying_an_eighth_pays_the_eighth_less_their_discount(): void
    {
        [$g, $b] = $this->strain(1000, 2500); // €10/g, eighth €25
        $d = $this->commit($this->member(withThirtyPercentOff: true), [['genetic_id' => $g->id, 'batch_id' => $b->id, 'grams_cg' => 350]]);

        $this->assertSame(1750, $d->total_cents->cents); // €25 − 30% = €17.50, NOT the flat €25
    }

    public function test_a_discounted_eighth_split_across_two_strains_is_exact(): void
    {
        [$a, $ab] = $this->strain(1000, 2500);
        [$b, $bb] = $this->strain(1200, 2500); // different per-gram, SAME eighth
        $d = $this->commit($this->member(withThirtyPercentOff: true), [
            ['genetic_id' => $a->id, 'batch_id' => $ab->id, 'grams_cg' => 175],
            ['genetic_id' => $b->id, 'batch_id' => $bb->id, 'grams_cg' => 175],
        ]);

        $this->assertSame(1750, $d->total_cents->cents); // one discounted eighth
        $this->assertSame([875, 875], $d->lines()->orderBy('id')->get()->map(fn ($l): int => $l->line_total_cents->cents)->all());
    }

    public function test_a_discounted_multiple_carries_the_discount(): void
    {
        [$g, $b] = $this->strain(1000, 2500);
        $d = $this->commit($this->member(withThirtyPercentOff: true), [['genetic_id' => $g->id, 'batch_id' => $b->id, 'grams_cg' => 700]]);

        $this->assertSame(3500, $d->total_cents->cents); // 7 g = two discounted eighths (2 × 1750)
    }

    public function test_a_discounted_member_is_never_charged_more_than_their_discounted_per_gram_total(): void
    {
        // Eighth set high (4000) so even at 30% off it would exceed the discounted per-gram total.
        [$g, $b] = $this->strain(1000, 4000);
        $d = $this->commit($this->member(withThirtyPercentOff: true), [['genetic_id' => $g->id, 'batch_id' => $b->id, 'grams_cg' => 350]]);

        // Discounted per-gram: 3.5 g × €10 = 3500, − 30% = 2450. The discounted eighth (2800) is worse → floored.
        $this->assertSame(2450, $d->total_cents->cents);
    }

    public function test_an_undiscounted_member_is_never_charged_more_than_per_gram(): void
    {
        [$g, $b] = $this->strain(1000, 4000); // eighth above 3.5 × per-gram
        $d = $this->commit($this->member(), [['genetic_id' => $g->id, 'batch_id' => $b->id, 'grams_cg' => 350]]);

        $this->assertSame(3500, $d->total_cents->cents); // per-gram, never the 4000 eighth
    }

    // --- The price-entry guard ---------------------------------------------------------------------------

    public function test_the_price_form_rejects_an_eighth_above_three_and_a_half_times_per_gram(): void
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value); // holds prices.manage
        $manager->locations()->sync([$this->location->id]);
        $this->actingAs($manager);

        Livewire::test(GeneticPricesRelationManager::class, ['ownerRecord' => $genetic, 'pageClass' => EditGenetic::class])
            ->callAction('create', [
                'location_id' => $this->location->id, 'tier_id' => null,
                'price_eur' => '10.00', 'price_per_eighth_eur' => '40.00', 'active' => true, // 40 > 3.5 × 10
            ])
            ->assertHasActionErrors(['price_per_eighth_eur']);

        // Exactly 3.5 × per-gram is allowed (the boundary), and below it too.
        Livewire::test(GeneticPricesRelationManager::class, ['ownerRecord' => $genetic, 'pageClass' => EditGenetic::class])
            ->callAction('create', [
                'location_id' => $this->location->id, 'tier_id' => null,
                'price_eur' => '10.00', 'price_per_eighth_eur' => '34.00', 'active' => true,
            ])
            ->assertHasNoActionErrors();
    }

    // --- The seed makes the feature reachable ------------------------------------------------------------

    public function test_a_freshly_seeded_database_has_two_genetics_sharing_an_eighth_price_that_splits(): void
    {
        Notification::fake();
        $this->app['env'] = 'local';
        $this->travelTo(Carbon::parse('2026-07-20 12:00:00'));
        $this->seed(DatabaseSeeder::class);

        // At least two genetics at one sede share a non-null eighth price.
        $shared = GeneticPrice::query()->withoutGlobalScopes()
            ->whereNotNull('price_per_eighth_cents')
            ->get()
            ->groupBy(fn (GeneticPrice $p): string => $p->location_id.':'.$p->price_per_eighth_cents)
            ->first(fn ($group) => $group->count() >= 2);

        $this->assertNotNull($shared, 'The seed must leave at least two genetics sharing an eighth price.');

        // A 1.75 g + 1.75 g basket across two of them is charged exactly one eighth.
        [$p1, $p2] = [$shared[0], $shared[1]];
        $eighth = (int) $p1->price_per_eighth_cents;
        $out = (new ResolvePrice)->applyEighthBreaks([
            ['grams_cg' => 175, 'rate_cents' => (int) $p1->price_per_gram_cents, 'per_gram_total' => (int) round_half_up($p1->price_per_gram_cents * 175 / 100), 'eighth_price' => $eighth],
            ['grams_cg' => 175, 'rate_cents' => (int) $p2->price_per_gram_cents, 'per_gram_total' => (int) round_half_up($p2->price_per_gram_cents * 175 / 100), 'eighth_price' => $eighth],
        ]);

        $this->assertSame($eighth, $out[0]['total_cents'] + $out[1]['total_cents']);
        $this->assertTrue($out[0]['eighth_applied']);
    }
}
