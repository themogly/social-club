<?php

namespace Tests\Feature\Socio;

use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Announcement;
use App\Models\Batch;
use App\Models\Event;
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
 * Prompt 95 — /socio/menu 500'd whenever any genetic had no active price at the member's location (an
 * ordinary half-priced strain), and soft-deleting the strain did not fix it (withoutGlobalScopes stripped
 * the soft-delete scope too). The menu now shares the dispensary POS's "sellable" filter: unpriced strains
 * are absent (not fatal), soft-deleted ones never reach a member, and the two surfaces agree.
 */
class MemberMenuCrashTest extends TestCase
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

    private function memberAt(?Location $location): Member
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE]);
        if ($location !== null) {
            $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
            Membership::factory()->create([
                'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $location->id,
                'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
            ]);
        }

        return $member;
    }

    private function genetic(string $name): Genetic
    {
        return Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => $name, 'active' => true, 'published' => true]);
    }

    private function priceAndStock(Genetic $g, Location $loc): void
    {
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $g->id, 'location_id' => $loc->id,
            'tier_id' => null, 'price_per_gram_cents' => 1000, 'active' => true,
        ]);
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $g->id, 'location_id' => $loc->id,
            'remaining_cg' => 50000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(),
        ]);
    }

    public function test_an_unpriced_genetic_does_not_crash_the_menu_and_is_absent(): void
    {
        $member = $this->memberAt($this->a);
        $this->priceAndStock($priced = $this->genetic('Priced Strain'), $this->a);
        $this->genetic('Unpriced Strain'); // created but never priced — the ordinary admin half-step

        $this->actingAs($member, 'member')->get(route('socio.menu'))
            ->assertOk()                          // no 500
            ->assertSee('Priced Strain')
            ->assertDontSee('Unpriced Strain');
    }

    public function test_a_genetic_priced_at_one_location_appears_there_and_not_at_the_other(): void
    {
        $this->priceAndStock($g = $this->genetic('Sede A Only'), $this->a);

        $atA = $this->memberAt($this->a);
        $atB = $this->memberAt($this->b);

        $this->actingAs($atA, 'member')->get(route('socio.menu'))->assertOk()->assertSee('Sede A Only');
        $this->actingAs($atB, 'member')->get(route('socio.menu'))->assertOk()->assertDontSee('Sede A Only');
    }

    public function test_a_soft_deleted_genetic_never_appears_priced_or_not(): void
    {
        $member = $this->memberAt($this->a);
        $this->priceAndStock($g = $this->genetic('Deleted Strain'), $this->a);
        $g->delete(); // soft delete — used to still 500 / still be advertised

        $this->actingAs($member, 'member')->get(route('socio.menu'))
            ->assertOk()
            ->assertDontSee('Deleted Strain');
    }

    public function test_a_member_with_no_active_location_gets_an_empty_menu_not_an_error(): void
    {
        $member = $this->memberAt(null); // no membership → no home location
        $this->priceAndStock($this->genetic('Somewhere Strain'), $this->a);

        $this->actingAs($member, 'member')->get(route('socio.menu'))
            ->assertOk()
            ->assertDontSee('Somewhere Strain');
    }

    public function test_the_menu_and_the_dispensary_pos_agree_on_what_is_sellable(): void
    {
        $member = $this->memberAt($this->a);
        $this->priceAndStock($this->genetic('Available Here'), $this->a);
        $this->priceAndStock($this->genetic('Available At B'), $this->b);
        $this->genetic('Never Priced');

        // The member menu.
        $this->actingAs($member, 'member')->get(route('socio.menu'))
            ->assertOk()->assertSee('Available Here')->assertDontSee('Available At B')->assertDontSee('Never Priced');

        // The dispensary POS at the same sede — same available set, from the same scopeSellableAt().
        $operator = User::factory()->create();
        $operator->assignRole(Role::MANAGER->value);
        $operator->locations()->sync([$this->a->id]);
        $this->actingAs($operator);
        CounterOperator::set($operator);
        app(ActiveScope::class)->setLocation($this->a->id);
        session(['counter.location_id' => $this->a->id]);

        // Prompt 175: the grid only renders on the usable screen — a till open and a socio identified. The
        // member here is the same one the menu was checked against, so both sides read the same sede.
        (new OpenTill)->handle($this->a, 'POS-1', 10000);

        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->assertSee('Available Here')->assertDontSee('Available At B')->assertDontSee('Never Priced');
    }

    public function test_a_soft_deleted_announcement_is_not_shown_to_members(): void
    {
        $member = $this->memberAt($this->a);
        $live = Announcement::factory()->create(['organisation_id' => $this->org->id, 'title' => 'Aviso vigente', 'published_at' => now()->subDay()]);
        $dead = Announcement::factory()->create(['organisation_id' => $this->org->id, 'title' => 'Aviso borrado', 'published_at' => now()->subDay()]);
        $dead->delete();

        $this->actingAs($member, 'member')->get(route('socio.announcements'))
            ->assertOk()->assertSee('Aviso vigente')->assertDontSee('Aviso borrado');
    }

    public function test_a_soft_deleted_event_is_not_shown_to_members(): void
    {
        $member = $this->memberAt($this->a);
        Event::factory()->create(['organisation_id' => $this->org->id, 'title' => 'Evento vigente', 'starts_at' => now()->addWeek()]);
        $dead = Event::factory()->create(['organisation_id' => $this->org->id, 'title' => 'Evento borrado', 'starts_at' => now()->addWeek()]);
        $dead->delete();

        $this->actingAs($member, 'member')->get(route('socio.events'))
            ->assertOk()->assertSee('Evento vigente')->assertDontSee('Evento borrado');
    }
}
