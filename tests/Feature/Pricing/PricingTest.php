<?php

namespace Tests\Feature\Pricing;

use App\Actions\Members\AssignMemberDiscount;
use App\Actions\Pricing\ResolvePrice;
use App\Enums\DiscountAppliesTo;
use App\Enums\DiscountKind;
use App\Enums\DiscountMode;
use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Models\Discount;
use App\Models\Dispensation;
use App\Models\DispensationLine;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $genetic;

    private Member $member;

    private MembershipTier $tier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->location->id,
            'tier_id' => null, 'price_per_gram_cents' => 749, 'active' => true,
        ]);
        $this->tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        $this->member = Member::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $this->member->id, 'location_id' => $this->location->id,
            'tier_id' => $this->tier->id, 'status' => MembershipStatus::ACTIVE,
        ]);
    }

    private function standardDiscount(int $bp, DiscountKind $kind = DiscountKind::LOCAL): Discount
    {
        $discount = Discount::factory()->create([
            'organisation_id' => $this->org->id, 'kind' => $kind, 'mode' => DiscountMode::PERCENT,
            'value_bp' => $bp, 'value_cents' => null, 'applies_to' => DiscountAppliesTo::BOTH, 'active' => true,
        ]);
        $discount->locations()->attach($this->location->id);

        return $discount;
    }

    public function test_tier_price_beats_base_price(): void
    {
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->location->id,
            'tier_id' => $this->tier->id, 'price_per_gram_cents' => 500, 'active' => true,
        ]);

        $result = (new ResolvePrice)->forGenetic($this->genetic, $this->location, $this->member);
        $this->assertSame(500, $result->ratePerGramCents);
    }

    public function test_best_single_discount_applies_and_does_not_stack_by_default(): void
    {
        $this->standardDiscount(1000); // 10%
        (new AssignMemberDiscount)->handle($this->member, $this->owner(), ['discount_id' => $this->standardDiscount(1500)->id]); // 15% assigned
        // also assign the 10% one so two are applicable
        (new AssignMemberDiscount)->handle($this->member, $this->owner(), ['discount_id' => Discount::query()->where('value_bp', 1000)->first()->id]);

        $line = (new ResolvePrice)->forGenetic($this->genetic, $this->location, $this->member->fresh())->lineFor(100);
        // 749 subtotal, best is 15% → 749*1500/10000 = 112.35 → 112
        $this->assertSame(112, $line['discount_cents']);
    }

    public function test_stacking_combines_when_the_setting_is_on(): void
    {
        Settings::set('discounts_stack', true, SettingType::BOOL);
        (new AssignMemberDiscount)->handle($this->member, $this->owner(), ['discount_id' => $this->standardDiscount(1000)->id]);
        (new AssignMemberDiscount)->handle($this->member, $this->owner(), ['discount_id' => $this->standardDiscount(1500)->id]);

        $line = (new ResolvePrice)->forGenetic($this->genetic, $this->location, $this->member->fresh())->lineFor(100);
        // combined 25% → 749*2500/10000 = 187.25 → 187
        $this->assertSame(187, $line['discount_cents']);
    }

    public function test_per_member_custom_overrides_a_standard_discount_when_better(): void
    {
        (new AssignMemberDiscount)->handle($this->member, $this->owner(), ['discount_id' => $this->standardDiscount(1000)->id]); // 10%
        (new AssignMemberDiscount)->handle($this->member, $this->owner(), ['mode' => DiscountMode::PERCENT, 'value_bp' => 3000]); // custom 30%

        $line = (new ResolvePrice)->forGenetic($this->genetic, $this->location, $this->member->fresh())->lineFor(100);
        $this->assertSame((int) round(749 * 3000 / 10000), $line['discount_cents']); // 225 (custom wins)
    }

    public function test_a_custom_discount_expires_on_its_date(): void
    {
        (new AssignMemberDiscount)->handle($this->member, $this->owner(), [
            'mode' => DiscountMode::PERCENT, 'value_bp' => 3000, 'expires_at' => now()->subDay(),
        ]);

        $line = (new ResolvePrice)->forGenetic($this->genetic, $this->location, $this->member->fresh())->lineFor(100);
        $this->assertSame(0, $line['discount_cents']); // expired → not applied
    }

    public function test_therapeutic_members_get_the_therapeutic_discount_automatically(): void
    {
        $this->member->update(['is_therapeutic' => true]);
        $discount = Discount::factory()->create([
            'organisation_id' => $this->org->id, 'kind' => DiscountKind::THERAPEUTIC, 'mode' => DiscountMode::PERCENT,
            'value_bp' => 2000, 'value_cents' => null, 'applies_to' => DiscountAppliesTo::BOTH, 'active' => true,
        ]);
        $discount->locations()->attach($this->location->id);

        $line = (new ResolvePrice)->forGenetic($this->genetic, $this->location, $this->member->fresh())->lineFor(100);
        $this->assertSame((int) round(749 * 2000 / 10000), $line['discount_cents']); // 150, no assignment needed
    }

    public function test_rounding_pins_the_exact_cents(): void
    {
        (new AssignMemberDiscount)->handle($this->member, $this->owner(), ['mode' => DiscountMode::PERCENT, 'value_bp' => 1750]);

        $line = (new ResolvePrice)->forGenetic($this->genetic, $this->location, $this->member->fresh())->lineFor(133); // 1.33 g
        $this->assertSame(996, $line['subtotal_cents']);  // 749 × 133 / 100 → 996
        $this->assertSame(174, $line['discount_cents']);  // 996 × 1750 / 10000 → 174
        $this->assertSame(822, $line['total_cents']);
    }

    public function test_assigning_a_member_discount_is_owner_only(): void
    {
        foreach ([Role::MANAGER, Role::STAFF] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role->value);
            try {
                (new AssignMemberDiscount)->handle($this->member, $user, ['mode' => DiscountMode::PERCENT, 'value_bp' => 1000]);
                $this->fail("{$role->value} should not assign a discount.");
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_a_price_change_does_not_alter_a_stored_dispensation(): void
    {
        $dispensation = Dispensation::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $this->member->id, 'location_id' => $this->location->id,
            'total_cents' => 996, 'cash_cents' => 996, 'wallet_cents' => 0,
        ]);
        $line = DispensationLine::factory()->create([
            'dispensation_id' => $dispensation->id, 'genetic_id' => $this->genetic->id,
            'grams_cg' => 133, 'price_per_gram_cents' => 749, 'discount_cents' => 0, 'line_total_cents' => 996,
        ]);

        // Change the live price afterwards.
        GeneticPrice::query()->where('genetic_id', $this->genetic->id)->whereNull('tier_id')
            ->update(['price_per_gram_cents' => 2000]);

        // The frozen snapshot is untouched.
        $this->assertSame(749, $line->fresh()->price_per_gram_cents);
        $this->assertSame(996, $line->fresh()->line_total_cents->cents);
    }

    private function owner(): User
    {
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);

        return $owner;
    }
}
