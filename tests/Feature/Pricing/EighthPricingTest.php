<?php

namespace Tests\Feature\Pricing;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Exceptions\LimitExceededException;
use App\Livewire\Counter\DispensaryPos;
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
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 83 — eighth (3.5 g = 350 cg) quantity-break pricing, including an eighth split across strains that
 * share an eighth price, computed over the WHOLE basket in ResolvePrice. Money is asserted in real stored
 * cents; line totals must sum EXACTLY to the charged total. Limits are on weight and untouched by pricing.
 */
class EighthPricingTest extends TestCase
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

    /** A WEIGHT genetic priced €10/g (1000) with an optional eighth price (cents). Returns [genetic, batch]. */
    private function strain(int $perGram = 1000, ?int $eighthCents = null): array
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

    private function member(int $dailyLimitCg = 100000): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(),
            'daily_limit_cg' => $dailyLimitCg, 'monthly_limit_cg' => 1000000,
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);

        return $member;
    }

    /** @param list<array{genetic_id: string, batch_id: string, grams_cg: int}> $lines */
    private function commit(Member $member, array $lines, array $options = []): Dispensation
    {
        return (new CommitDispensation)->handle($member, $this->location, $lines, $options);
    }

    public function test_a_single_strain_at_exactly_an_eighth_charges_the_eighth_price(): void
    {
        [$g, $b] = $this->strain(1000, 3000); // €10/g, eighth €30
        $d = $this->commit($this->member(), [['genetic_id' => $g->id, 'batch_id' => $b->id, 'grams_cg' => 350]]);

        $this->assertSame(3000, $d->total_cents->cents); // the eighth, NOT 3.5 × €10 = €35
        $this->assertSame(__('Octavo (1/8)'), $d->lines()->first()->pricing_note);
    }

    public function test_two_strains_split_one_eighth_at_the_same_price(): void
    {
        [$a, $ab] = $this->strain(1000, 3000);
        [$b, $bb] = $this->strain(1200, 3000); // different per-gram, SAME eighth price
        $d = $this->commit($this->member(), [
            ['genetic_id' => $a->id, 'batch_id' => $ab->id, 'grams_cg' => 175],
            ['genetic_id' => $b->id, 'batch_id' => $bb->id, 'grams_cg' => 175],
        ]);

        $this->assertSame(3000, $d->total_cents->cents); // one eighth, computed in the background
        $this->assertSame([1500, 1500], $d->lines()->orderBy('id')->get()->map(fn ($l): int => $l->line_total_cents->cents)->all());
    }

    public function test_different_eighth_prices_fall_back_to_per_gram(): void
    {
        [$a, $ab] = $this->strain(1000, 3000);
        [$b, $bb] = $this->strain(1000, 3200); // eighth prices DIFFER
        $d = $this->commit($this->member(), [
            ['genetic_id' => $a->id, 'batch_id' => $ab->id, 'grams_cg' => 175],
            ['genetic_id' => $b->id, 'batch_id' => $bb->id, 'grams_cg' => 175],
        ]);

        // Neither group reaches 3.5 g alone → both per-gram: 1.75 g × €10 = €17.50 each.
        $this->assertSame(3500, $d->total_cents->cents);
        $this->assertNull($d->lines()->first()->pricing_note);
    }

    public function test_line_totals_sum_exactly_to_the_charged_total(): void
    {
        // An eighth price that does not divide evenly by grams forces a rounding decision (largest-remainder).
        [$a, $ab] = $this->strain(1000, 2999); // €29.99 eighth
        [$b, $bb] = $this->strain(1000, 2999);
        $d = $this->commit($this->member(), [
            ['genetic_id' => $a->id, 'batch_id' => $ab->id, 'grams_cg' => 117],
            ['genetic_id' => $b->id, 'batch_id' => $bb->id, 'grams_cg' => 233], // 117 + 233 = 350 cg
        ]);

        $sum = (int) $d->lines()->sum('line_total_cents');
        $this->assertSame($d->total_cents->cents, $sum);
        $this->assertSame(2999, $d->total_cents->cents); // exactly one eighth, no cent lost or gained
    }

    public function test_under_an_eighth_is_per_gram_and_over_is_eighth_plus_remainder(): void
    {
        [$g, $b] = $this->strain(1000, 3000);

        $under = $this->commit($this->member(), [['genetic_id' => $g->id, 'batch_id' => $b->id, 'grams_cg' => 340]]);
        $this->assertSame(3400, $under->total_cents->cents); // 3.4 g < eighth → per-gram (3.4 × €10)

        $over = $this->commit($this->member(), [['genetic_id' => $g->id, 'batch_id' => $b->id, 'grams_cg' => 360]]);
        $this->assertSame(3100, $over->total_cents->cents); // one eighth €30 + 0.1 g × €10 = €31.00
    }

    public function test_the_committed_total_equals_the_basket_total_shown(): void
    {
        [$a, $ab] = $this->strain(1000, 3000);
        [$b, $bb] = $this->strain(1000, 3000);
        $member = $this->member();

        $user = User::factory()->create();
        $user->assignRole(Role::STAFF->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('chooseGenetic', $a->id)->set('weightInput', '1.75')->call('addLine')
            ->call('chooseGenetic', $b->id)->set('weightInput', '1.75')->call('addLine')
            ->assertViewHas('basketTotalCents', 3000) // shown as one eighth
            ->call('commit')
            ->assertSet('flashType', 'success');

        $this->assertSame(3000, Dispensation::query()->withoutGlobalScopes()->firstOrFail()->total_cents->cents);
    }

    public function test_a_price_override_reduces_from_the_eighth_total(): void
    {
        [$g, $b] = $this->strain(1000, 3000);
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value); // dispensation.price.override

        $d = $this->commit($this->member(), [['genetic_id' => $g->id, 'batch_id' => $b->id, 'grams_cg' => 350]], [
            'price_override_cents' => 2500, 'price_override_reason' => 'Goodwill', 'price_override_by' => $manager,
        ]);

        $this->assertSame(2500, $d->total_cents->cents);          // charged, reduced from the eighth
        $this->assertSame(3000, $d->original_total_cents->cents); // the resolved EIGHTH total is kept
    }

    public function test_a_member_at_the_daily_limit_is_still_blocked_regardless_of_pricing(): void
    {
        [$g, $b] = $this->strain(1000, 3000);
        // Daily limit exactly one eighth (350 cg). 3.6 g breaches it — eighth pricing is no route around the limit.
        $this->expectException(LimitExceededException::class);
        $this->commit($this->member(350), [['genetic_id' => $g->id, 'batch_id' => $b->id, 'grams_cg' => 360]]);
    }

    public function test_per_gram_pricing_is_unchanged_where_no_eighth_applies(): void
    {
        [$g, $b] = $this->strain(1000, null); // NO eighth price
        $d = $this->commit($this->member(), [['genetic_id' => $g->id, 'batch_id' => $b->id, 'grams_cg' => 350]]);

        $this->assertSame(3500, $d->total_cents->cents); // 3.5 g × €10 = €35, plain per-gram
        $this->assertNull($d->lines()->first()->pricing_note);
    }
}
