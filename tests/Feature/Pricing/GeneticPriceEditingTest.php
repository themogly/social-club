<?php

namespace Tests\Feature\Pricing;

use App\Actions\Pricing\ResolvePrice;
use App\Actions\Pricing\SaveGeneticPrice;
use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Filament\Resources\Genetics\Pages\EditGenetic;
use App\Filament\Resources\Genetics\RelationManagers\GeneticPricesRelationManager;
use App\Models\AuditLog;
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

/**
 * Prompt 63 — the (previously non-existent) GeneticPrice edit surface. A price written through the new
 * SaveGeneticPrice action is what ResolvePrice returns at the POS; tier → base precedence holds and falls
 * back cleanly; the unit_type one-of-two is enforced by construction; edits are audited with real cents;
 * the surface is gated on prices.manage; and editing a price never rewrites a past dispensation's total.
 */
class GeneticPriceEditingTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $weight;

    private Genetic $unit;

    private MembershipTier $tier;

    private Member $memberWithTier;

    private Member $memberNoTier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->weight = Genetic::factory()->create(['organisation_id' => $this->org->id]);           // WEIGHT
        $this->unit = Genetic::factory()->preroll(70)->create(['organisation_id' => $this->org->id]); // UNIT

        $this->tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        $this->memberWithTier = Member::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $this->memberWithTier->id, 'location_id' => $this->location->id,
            'tier_id' => $this->tier->id, 'status' => MembershipStatus::ACTIVE,
        ]);
        $this->memberNoTier = Member::factory()->create(['organisation_id' => $this->org->id]);
    }

    public function test_a_price_saved_through_the_action_is_what_resolveprice_returns(): void
    {
        (new SaveGeneticPrice)->handle($this->weight, $this->location, null, 749);

        $result = (new ResolvePrice)->forGenetic($this->weight, $this->location, $this->memberNoTier);
        $this->assertSame(749, $result->ratePerGramCents);
    }

    public function test_tier_precedence_holds_and_falls_back_when_the_tier_row_is_deleted(): void
    {
        (new SaveGeneticPrice)->handle($this->weight, $this->location, null, 749);                 // base
        $tierPrice = (new SaveGeneticPrice)->handle($this->weight, $this->location, $this->tier->id, 500); // tier

        // The tier member gets the tier price; everyone else gets the base.
        $this->assertSame(500, (new ResolvePrice)->forGenetic($this->weight, $this->location, $this->memberWithTier)->ratePerGramCents);
        $this->assertSame(749, (new ResolvePrice)->forGenetic($this->weight, $this->location, $this->memberNoTier)->ratePerGramCents);

        // Deleting the tier row falls back to the base — never fails.
        $tierPrice->delete();
        $this->assertSame(749, (new ResolvePrice)->forGenetic($this->weight, $this->location, $this->memberWithTier->fresh())->ratePerGramCents);
    }

    public function test_the_action_always_writes_the_column_matching_unit_type(): void
    {
        $weightPrice = (new SaveGeneticPrice)->handle($this->weight, $this->location, null, 749);
        $this->assertSame(749, $weightPrice->price_per_gram_cents);
        $this->assertNull($weightPrice->price_per_unit_cents);

        $unitPrice = (new SaveGeneticPrice)->handle($this->unit, $this->location, null, 800);
        $this->assertSame(800, $unitPrice->price_per_unit_cents);
        $this->assertNull($unitPrice->price_per_gram_cents);
    }

    public function test_editing_a_price_writes_a_genetic_price_updated_audit_with_real_cents(): void
    {
        $price = (new SaveGeneticPrice)->handle($this->weight, $this->location, null, 749);
        (new SaveGeneticPrice)->handle($this->weight, $this->location, null, 800, existing: $price->fresh());

        $audit = AuditLog::query()->where('action', 'genetic.price.updated')
            ->whereNotNull('before')->latest()->first();

        $this->assertNotNull($audit);
        $this->assertSame(749, $audit->before['price_per_gram_cents']);
        $this->assertSame(800, $audit->after['price_per_gram_cents']);
    }

    public function test_the_prices_tab_is_denied_without_prices_manage(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $this->actingAs($manager);
        $this->assertTrue(GeneticPricesRelationManager::canViewForRecord($this->weight, EditGenetic::class));

        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);
        $this->actingAs($staff);
        $this->assertFalse(GeneticPricesRelationManager::canViewForRecord($this->weight, EditGenetic::class));
    }

    public function test_editing_a_price_does_not_change_an_existing_dispensation_total(): void
    {
        $price = (new SaveGeneticPrice)->handle($this->weight, $this->location, null, 749);

        // A past dispensation froze its total at commit.
        $dispensation = Dispensation::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $this->memberWithTier->id,
            'location_id' => $this->location->id, 'total_cents' => 749, 'cash_cents' => 749, 'wallet_cents' => 0,
        ]);

        (new SaveGeneticPrice)->handle($this->weight, $this->location, null, 800, existing: $price->fresh());

        $this->assertSame(749, $dispensation->fresh()->total_cents->cents); // snapshot untouched
    }
}
