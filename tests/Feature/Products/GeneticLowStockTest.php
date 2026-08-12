<?php

namespace Tests\Feature\Products;

use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Batch;
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
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 54 — genetics had a low-stock threshold column (per GeneticPrice) + an org-wide fallback setting
 * with NO consumer. Now the threshold resolves (per-price → org fallback) and the dispensary POS flags a
 * genetic whose on-hand gram-equivalent is at or below it — the parallel to the bar's article low-stock.
 */
class GeneticLowStockTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $genetic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        Settings::set('low_stock_threshold_cg', 5000, SettingType::CG); // org fallback = 50 g
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
    }

    public function test_the_threshold_resolves_per_price_then_falls_back_to_the_org_setting(): void
    {
        // No per-price threshold → org fallback.
        $this->assertSame(5000, $this->genetic->lowStockThresholdCg($this->location->id));

        // A per-price base row's threshold wins at that sede.
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->location->id,
            'tier_id' => null, 'price_per_gram_cents' => 749, 'low_stock_threshold_cg' => 200, 'active' => true,
        ]);

        $this->assertSame(200, $this->genetic->fresh()->lowStockThresholdCg($this->location->id));
    }

    public function test_is_low_stock_at_uses_at_or_below_the_threshold(): void
    {
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->location->id,
            'tier_id' => null, 'price_per_gram_cents' => 749, 'low_stock_threshold_cg' => 200, 'active' => true,
        ]);
        $genetic = $this->genetic->fresh();

        $this->assertTrue($genetic->isLowStockAt(150, $this->location->id));
        $this->assertTrue($genetic->isLowStockAt(200, $this->location->id));   // at the threshold
        $this->assertFalse($genetic->isLowStockAt(250, $this->location->id));
    }

    public function test_the_dispensary_pos_flags_a_low_stock_genetic(): void
    {
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->location->id,
            'tier_id' => null, 'price_per_gram_cents' => 749, 'low_stock_threshold_cg' => 200, 'active' => true,
        ]);
        // A dispensable batch with only 100 cg remaining — below the 200 cg threshold.
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->location->id,
            'initial_cg' => 5000, 'remaining_cg' => 100, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addMonths(6),
        ]);

        $user = User::factory()->create();
        $user->assignRole(Role::STAFF->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);
        // Prompt 175: the genetics grid only renders on the usable screen — a till open and a socio
        // identified. Without them the dispensary is a blocking state and there is no grid to assert on.
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        // Prompt 225: the dispensary's catalogue renders for a socio who may be DISPENSED TO — a
        // present-but-blocked member replaces the selling surface exactly as a missing one does. The fixture
        // is amended, not the feature: this test is about what the catalogue says, so its socio is now a
        // socio who can be sold to. (The comment above already made 175's version of the point.)
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id,
            'status' => MemberStatus::ACTIVE,
            'carencia_ends_at' => now()->subMonth(),
        ]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
            'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);

        Livewire::test(DispensaryPos::class)->call('selectMember', $member->id)->assertSee(__('Stock bajo'));
    }
}
