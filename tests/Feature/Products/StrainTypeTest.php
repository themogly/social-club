<?php

namespace Tests\Feature\Products;

use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\StrainType;
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
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 66 — sativa/indica/hybrid strain type on the genetic, the orthogonal axis that makes the POS
 * filter rows earn their place. Persists + translates; the POS filters by it and the rows are LABELLED
 * (the a11y + "reads-as-duplicate" fix); a strain selection returns a set distinct from a product-type
 * selection; and it shows on the member menu.
 */
class StrainTypeTest extends TestCase
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

    private function genetic(string $name, ?StrainType $strain): Genetic
    {
        $genetic = Genetic::factory()->create([
            'organisation_id' => $this->org->id, 'name' => $name, 'strain_type' => $strain, 'active' => true, 'published' => true,
        ]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'tier_id' => null, 'price_per_gram_cents' => 800, 'active' => true,
        ]);
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'initial_cg' => 50000, 'remaining_cg' => 50000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addMonths(6),
        ]);

        return $genetic;
    }

    private function operator(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::STAFF->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);
    }

    /**
     * Prompt 175: the genetics grid and its filter rows only render on the usable screen — a till open and a
     * socio identified. Below those links in the chain the dispensary is a blocking state, with no grid.
     */
    private function pos(): Testable
    {
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

        return Livewire::test(DispensaryPos::class)->call('selectMember', $member->id);
    }

    public function test_it_persists_and_translates_in_both_locales(): void
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'strain_type' => StrainType::HYBRID]);

        $this->assertSame(StrainType::HYBRID, $genetic->fresh()->strain_type);
        app()->setLocale('es');
        $this->assertSame('Híbrida', $genetic->strain_type->label());
        app()->setLocale('en');
        $this->assertSame('Hybrid', $genetic->strain_type->label());
    }

    public function test_the_pos_filters_by_strain_and_the_rows_are_labelled(): void
    {
        $this->genetic('SativaOne', StrainType::SATIVA);
        $this->genetic('IndicaOne', StrainType::INDICA);
        $this->operator();

        $this->pos()
            // Labelled rows (a11y): each filter group carries its axis label.
            ->assertSeeHtml('aria-label="'.e(__('Variedad')).'"')
            ->assertSee('SativaOne')
            ->assertSee('IndicaOne')
            // Filtering by strain narrows to that variety only.
            ->call('filterStrainType', StrainType::SATIVA->value)
            ->assertSee('SativaOne')
            ->assertDontSee('IndicaOne');
    }

    public function test_a_strain_selection_differs_from_a_product_type_selection(): void
    {
        // Both are FLOWER, so product-type FLOWER returns both, but SATIVA returns only one.
        $this->genetic('SativaTwo', StrainType::SATIVA);
        $this->genetic('IndicaTwo', StrainType::INDICA);
        $this->operator();

        $this->pos()
            ->call('filterProductType', 'FLOWER')
            ->assertSee('SativaTwo')->assertSee('IndicaTwo')   // product type: both
            ->call('filterProductType', null)
            ->call('filterStrainType', StrainType::SATIVA->value)
            ->assertSee('SativaTwo')->assertDontSee('IndicaTwo'); // strain: a different, narrower set
    }

    public function test_a_genetic_with_no_strain_type_still_shows(): void
    {
        $this->genetic('NoVariety', null);
        $this->operator();

        $this->pos()->assertSee('NoVariety'); // renders under "all", no badge
    }

    public function test_strain_type_shows_on_the_member_menu(): void
    {
        $genetic = $this->genetic('MenuSativa', StrainType::SATIVA);
        $genetic->update(['published' => true]);
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE, 'email' => 'm@x.es']);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
        ]);

        $this->actingAs($member, 'member')->get(route('socio.menu'))
            ->assertOk()
            ->assertSee(StrainType::SATIVA->label());
    }
}
