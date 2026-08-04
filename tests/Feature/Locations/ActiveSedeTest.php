<?php

namespace Tests\Feature\Locations;

use App\Enums\Role;
use App\Filament\Resources\Batches\Pages\CreateBatch;
use App\Filament\Resources\Batches\Pages\ListBatches;
use App\Filament\Resources\Locations\LocationResource;
use App\Livewire\LocationSwitcher as LocationSwitcherComponent;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\LocationSwitcher;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 148 — the active sede is never ambiguous. A single-sede org names its one sede instead of an "All
 * locations" rollup of one; the rollup is offered only to an owner who can reach more than one; and a batch is
 * REFUSED rather than attributed to an arbitrary sede when none is active (a per-premises stock-ceiling and
 * registro-de-dispensación matter, not cosmetic). Run on MySQL.
 */
class ActiveSedeTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
    }

    private function owner(): User
    {
        $u = User::factory()->create();
        $u->assignRole(Role::OWNER->value);

        return $u;
    }

    private function location(bool $active = true): Location
    {
        return Location::factory()->create(['organisation_id' => $this->org->id, 'active' => $active]);
    }

    public function test_an_owner_of_one_sede_has_it_active_by_default_and_no_rollup(): void
    {
        $owner = $this->owner();
        $location = $this->location();
        $this->actingAs($owner);

        $switcher = app(LocationSwitcher::class);
        $this->assertFalse($switcher->canSwitchToAll($owner));                 // no "All locations"
        $this->assertSame($location->id, $switcher->defaultLocationId($owner));

        Livewire::test(LocationSwitcherComponent::class)->assertSet('active', $location->id); // the topbar names it
    }

    public function test_an_owner_of_two_sedes_still_gets_the_rollup_and_defaults_to_it(): void
    {
        $owner = $this->owner();
        $this->location();
        $this->location();
        $this->actingAs($owner);

        $switcher = app(LocationSwitcher::class);
        $this->assertTrue($switcher->canSwitchToAll($owner));
        $this->assertNull($switcher->defaultLocationId($owner));

        Livewire::test(LocationSwitcherComponent::class)->assertSet('active', null); // stays in the rollup
    }

    public function test_deactivating_a_location_collapses_the_switcher_to_the_one_remaining(): void
    {
        $owner = $this->owner();
        $a = $this->location();
        $b = $this->location();
        $this->actingAs($owner);
        $this->assertTrue(app(LocationSwitcher::class)->canSwitchToAll($owner));

        $b->update(['active' => false]);

        $switcher = app(LocationSwitcher::class);
        $this->assertFalse($switcher->canSwitchToAll($owner));
        $this->assertSame($a->id, $switcher->defaultLocationId($owner));
    }

    public function test_a_manager_with_one_assigned_sede_sees_it_named_and_cannot_rollup(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $location = $this->location();
        $manager->locations()->sync([$location->id]);
        $this->actingAs($manager);

        $switcher = app(LocationSwitcher::class);
        $this->assertFalse($switcher->canSwitchToAll($manager));
        $this->assertSame($location->id, $switcher->defaultLocationId($manager));
    }

    public function test_creating_a_batch_with_no_active_sede_is_refused_not_guessed(): void
    {
        $owner = $this->owner();
        $this->location();
        $this->location(); // two sedes → owner defaults to the rollup (no active sede)
        $this->actingAs($owner);
        app(ActiveScope::class)->setLocation(null); // the rollup
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);

        Livewire::test(CreateBatch::class)
            ->fillForm(['genetic_id' => $genetic->id, 'grams' => 100, 'cost_per_gram_eur' => 3])
            ->call('create')
            ->assertNotified();

        $this->assertSame(0, Batch::query()->withoutGlobalScopes()->count()); // nothing guessed into existence
    }

    public function test_a_batch_is_created_at_the_active_sede_never_an_arbitrary_one(): void
    {
        $owner = $this->owner();
        $a = $this->location(); // created first — a "pick the first row" bug would land here
        $b = $this->location();
        $this->actingAs($owner);
        app(ActiveScope::class)->setLocation($b->id); // working IN sede B
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);

        Livewire::test(CreateBatch::class)
            ->fillForm(['genetic_id' => $genetic->id, 'grams' => 100, 'cost_per_gram_eur' => 3])
            ->call('create')
            ->assertHasNoFormErrors();

        $batch = Batch::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame($b->id, $batch->location_id);   // the ACTIVE sede
        $this->assertNotSame($a->id, $batch->location_id); // not the first row
    }

    public function test_the_batches_list_shows_the_location_only_when_more_than_one_exists(): void
    {
        $owner = $this->owner();
        $a = $this->location();
        $b = $this->location();
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        Batch::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $b->id, 'genetic_id' => $genetic->id]);
        $this->actingAs($owner);
        app(ActiveScope::class)->setLocation($b->id);

        Livewire::test(ListBatches::class)->assertCanRenderTableColumn('location.name');
    }

    public function test_zero_locations_the_switcher_does_not_error_and_the_owner_can_reach_locations_create(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        Livewire::test(LocationSwitcherComponent::class)->assertSet('active', null); // no error, no default
        $this->get(LocationResource::getUrl('create'))->assertOk();
    }
}
