<?php

namespace Tests\Feature\Products;

use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\Role;
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
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The dispensary POS switches entry mode by the genetic's unit_type: a UNIT genetic
 * shows the unit stepper, a WEIGHT genetic the grams pad. The compliance gauge reads
 * the SAME gram-equivalent whether the operator steps units or weighs grams.
 */
class DispensaryPosUnitTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $preroll;

    private Genetic $flower;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);

        $this->preroll = Genetic::factory()->preroll(70)->create(['organisation_id' => $this->org->id, 'name' => 'House Preroll']);
        GeneticPrice::factory()->perUnit(800)->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->preroll->id, 'location_id' => $this->location->id,
        ]);
        Batch::factory()->units(100)->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->preroll->id,
            'location_id' => $this->location->id, 'status' => BatchStatus::OPEN,
        ]);

        $this->flower = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'House Flower']);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->flower->id,
            'location_id' => $this->location->id, 'price_per_gram_cents' => 1000,
        ]);
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->flower->id,
            'location_id' => $this->location->id, 'remaining_cg' => 100000, 'status' => BatchStatus::OPEN,
        ]);
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::STAFF->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);

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

    public function test_selecting_a_unit_genetic_shows_the_stepper_and_a_weight_genetic_the_grams_pad(): void
    {
        $this->operator();
        $member = $this->member();

        $component = Livewire::test(DispensaryPos::class)->call('selectMember', $member->id);

        // UNIT genetic → unit stepper, no grams calculator.
        $component->call('chooseGenetic', $this->preroll->id)
            ->assertSet('unitQty', 1)
            ->assertSee('stepUnits')
            ->assertDontSee('toggleCalculator');

        // WEIGHT genetic → grams pad + calculator, no stepper.
        $component->call('chooseGenetic', $this->flower->id)
            ->assertSee('toggleCalculator')
            ->assertDontSee('stepUnits');
    }

    public function test_the_gauge_reads_the_same_gram_equivalent_for_an_equivalent_unit_and_weight_entry(): void
    {
        $this->operator();
        $member = $this->member();

        $component = Livewire::test(DispensaryPos::class)->call('selectMember', $member->id);

        // 3 prerolls × 0.70 g = 2.10 g equivalent (updates live as the count steps).
        $component->call('chooseGenetic', $this->preroll->id);
        $this->assertSame(70, $component->instance()->activeEntryGramsCg()); // 1 unit default
        $component->set('unitQty', 3);
        $unitEquivalent = $component->instance()->activeEntryGramsCg();
        $this->assertSame(210, $unitEquivalent);

        // 2.10 g weighed on a flower genetic → the identical gram-equivalent.
        $component->call('chooseGenetic', $this->flower->id)->set('weightInput', '2,10');
        $weightEquivalent = $component->instance()->activeEntryGramsCg();

        $this->assertSame(210, $weightEquivalent);
        $this->assertSame($unitEquivalent, $weightEquivalent); // the gauge cannot disagree between the two
    }
}
