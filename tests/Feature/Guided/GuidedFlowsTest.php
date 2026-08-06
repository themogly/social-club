<?php

namespace Tests\Feature\Guided;

use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Filament\Resources\Genetics\GeneticResource;
use App\Filament\Resources\Genetics\Pages\CreateGenetic;
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
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 93 — a record can look Active + Published and do nothing, because the chain across screens is
 * incomplete. Completeness is DERIVED, never stored, and surfaced on the record and the list; guidance
 * carries the user onward but never forces — stopping leaves a visible incomplete state.
 */
class GuidedFlowsTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $a;

    private Location $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->a = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->b = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function genetic(): Genetic
    {
        return Genetic::factory()->create(['organisation_id' => $this->org->id, 'active' => true, 'published' => true, 'name' => 'Amnesia Test']);
    }

    private function priceAt(Genetic $g, Location $loc): GeneticPrice
    {
        return GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $g->id, 'location_id' => $loc->id,
            'tier_id' => null, 'price_per_gram_cents' => 1000, 'active' => true,
        ]);
    }

    private function batchAt(Genetic $g, Location $loc): Batch
    {
        return Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $g->id, 'location_id' => $loc->id,
            'remaining_cg' => 50000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(),
        ]);
    }

    private function operatorAt(Location $loc): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::MANAGER->value);
        $user->locations()->sync([$loc->id]);
        $this->actingAs($user);
        CounterOperator::set($user);
        app(ActiveScope::class)->setLocation($loc->id);

        return $user;
    }

    // The case that proves it: incomplete IN THE LIST and absent FROM THE POS must be asserted together,
    // since the whole point is that they currently disagree silently.
    public function test_a_genetic_without_a_price_is_flagged_and_absent_from_that_locations_pos(): void
    {
        $g = $this->genetic(); // active + published, but no price, no batch

        // Flagged incomplete (the value the list badge renders).
        $this->assertSame('no_price', $g->completenessReason());

        // …and genuinely absent from that location's POS.
        $this->operatorAt($this->a);
        Livewire::test(DispensaryPos::class)->assertDontSee('Amnesia Test');
    }

    public function test_adding_a_price_clears_the_flag_for_that_location_only(): void
    {
        $g = $this->genetic();
        $this->priceAt($g, $this->a);

        $this->assertTrue($g->hasActivePriceAt($this->a->id));
        $this->assertFalse($g->hasActivePriceAt($this->b->id)); // only that location
        // Priced but no batch → the flag moves on to "no stock", not cleared entirely.
        $this->assertSame('no_stock', $g->fresh()->completenessReason());
    }

    public function test_a_priced_genetic_with_a_batch_is_ready_and_appears_at_the_pos(): void
    {
        $g = $this->genetic();
        $this->priceAt($g, $this->a);
        $this->batchAt($g, $this->a);

        $this->assertNull($g->fresh()->completenessReason()); // ready — not flagged (regression: no false alarm)

        $this->operatorAt($this->a);
        // Prompt 175: the genetics grid only renders on the usable screen — a till open and a socio
        // identified. Without them the dispensary is a blocking state and there is no grid to assert on.
        (new OpenTill)->handle($this->a, 'POS-1', 10000);
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);

        Livewire::test(DispensaryPos::class)->call('selectMember', $member->id)->assertSee('Amnesia Test');
    }

    public function test_a_member_without_a_membership_is_flagged(): void
    {
        $bare = Member::factory()->create(['organisation_id' => $this->org->id]);
        $this->assertFalse($bare->hasActiveMembership());

        $enrolled = Member::factory()->create(['organisation_id' => $this->org->id]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $enrolled->id, 'location_id' => $this->a->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
        ]);
        $this->assertTrue($enrolled->hasActiveMembership()); // regression: a complete member is not flagged
    }

    public function test_a_user_missing_a_role_a_location_or_a_pin_is_flagged(): void
    {
        $bare = User::factory()->create(['pin' => null]); // no role, no pin, no location
        $this->assertEqualsCanonicalizing(['no_role', 'no_location', 'no_pin'], $bare->setupIncompleteReasons());

        $ready = User::factory()->create(['pin' => '1234']);
        $ready->assignRole(Role::MANAGER->value);
        $ready->locations()->sync([$this->a->id]);
        $this->assertSame([], $ready->fresh()->setupIncompleteReasons()); // regression
    }

    public function test_a_location_without_prices_is_flagged(): void
    {
        $this->assertFalse($this->a->hasActivePrices());

        $this->priceAt($this->genetic(), $this->a);
        $this->assertTrue($this->a->fresh()->hasActivePrices());
        $this->assertFalse($this->b->fresh()->hasActivePrices()); // only that sede
    }

    public function test_creating_a_genetic_guides_onward_and_skipping_leaves_it_incomplete(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$this->a->id]);
        $this->actingAs($owner);

        $component = Livewire::test(CreateGenetic::class)
            ->fillForm(['name' => 'Nueva Cepa'])
            ->call('create')
            ->assertHasNoFormErrors();

        $genetic = Genetic::query()->where('name', 'Nueva Cepa')->firstOrFail();

        // Guided onward: redirected to the genetic's own page (where prices are added), not the list.
        $component->assertRedirect(GeneticResource::getUrl('edit', ['record' => $genetic]));

        // And stopping is safe: the record is VISIBLY incomplete, not apparently complete.
        $this->assertSame('no_price', $genetic->completenessReason());
    }
}
