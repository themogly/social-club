<?php

namespace Tests\Feature\Batches;

use App\Enums\Role;
use App\Filament\Resources\Batches\Pages\CreateBatch;
use App\Filament\Resources\Batches\Pages\ListBatches;
use App\Filament\Resources\Genetics\GeneticResource;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 238 — a batch names the sede it belongs to.
 *
 * Stock always belongs to a sede: `batches.location_id` drives the per-premises legal stock ceiling and the
 * registro de dispensación. Intake used to inherit the sede invisibly from the active scope and REFUSE in the
 * "all sedes" rollup (prompt 148, correctly — guessing a sede is a compliance failure). This branch turns the
 * refusal into a choice: a required sede Select on the form that defaults to the topbar scope, is blank in the
 * rollup so an owner picks deliberately, and is pre-filled and locked when the club has a single sede.
 *
 * And the confirmation closes the `no_price` gap: a genetic with stock but no active price at a sede is simply
 * absent from that sede's POS (prompt 95 — filtered, never an error), so adding stock there and then not
 * finding it at the counter was a silent trap. The confirmation names it at the moment it is created.
 */
class BatchNamesItsSedeTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        Filament::setCurrentPanel('admin');
    }

    private function secondSede(): Location
    {
        return Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte']);
    }

    /** A by-weight genetic; priced at the given sede only when $priceAt is passed. */
    private function genetic(string $name = 'Amnesia', ?string $priceAt = null): Genetic
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => $name, 'active' => true]);

        if ($priceAt !== null) {
            GeneticPrice::factory()->create([
                'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $priceAt,
                'tier_id' => null, 'price_per_gram_cents' => 1000, 'price_per_unit_cents' => null, 'active' => true,
            ]);
        }

        return $genetic;
    }

    // --- The Select's behaviour --------------------------------------------------

    public function test_the_sede_defaults_to_the_active_scope(): void
    {
        $this->secondSede();
        app(ActiveScope::class)->setLocation($this->centro->id);

        Livewire::actingAs($this->owner)
            ->test(CreateBatch::class)
            ->assertFormSet(['location_id' => $this->centro->id]);
    }

    public function test_the_sede_is_blank_in_the_all_sedes_rollup(): void
    {
        $this->secondSede();
        app(ActiveScope::class)->setLocation(null); // the owner's rollup — no scope to inherit

        Livewire::actingAs($this->owner)
            ->test(CreateBatch::class)
            ->assertFormSet(['location_id' => null]);
    }

    public function test_a_single_sede_locks_the_field_and_prefills_it(): void
    {
        // Only $this->centro exists — nothing to choose.
        app(ActiveScope::class)->setLocation($this->centro->id);

        Livewire::actingAs($this->owner)
            ->test(CreateBatch::class)
            ->assertFormSet(['location_id' => $this->centro->id])
            ->assertFormFieldIsDisabled('location_id');
    }

    // --- The sede is required and honoured, even from the rollup ------------------

    public function test_a_batch_created_from_the_rollup_lands_at_the_chosen_sede(): void
    {
        $norte = $this->secondSede();
        app(ActiveScope::class)->setLocation(null); // rollup — the old code REFUSED here
        $genetic = $this->genetic(priceAt: $norte->id);

        Livewire::actingAs($this->owner)
            ->test(CreateBatch::class)
            ->fillForm([
                'location_id' => $norte->id,
                'genetic_id' => $genetic->id,
                'grams' => 100,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $batch = Batch::query()->withoutGlobalScopes()->sole();
        $this->assertSame($norte->id, $batch->location_id, 'the batch did not land at the chosen sede');
        $this->assertSame($this->org->id, $batch->organisation_id);
    }

    public function test_the_sede_is_required(): void
    {
        $this->secondSede();
        app(ActiveScope::class)->setLocation(null);
        $genetic = $this->genetic();

        Livewire::actingAs($this->owner)
            ->test(CreateBatch::class)
            ->fillForm(['genetic_id' => $genetic->id, 'grams' => 100]) // no sede
            ->call('create')
            ->assertHasFormErrors(['location_id' => 'required']);

        $this->assertSame(0, Batch::query()->withoutGlobalScopes()->count());
    }

    // --- The confirmation, and the no_price consequence --------------------------

    public function test_adding_stock_where_the_genetic_has_no_price_warns_with_a_link(): void
    {
        app(ActiveScope::class)->setLocation($this->centro->id);
        $genetic = $this->genetic('Sin Precio'); // NOT priced at Centro

        Livewire::actingAs($this->owner)
            ->test(CreateBatch::class)
            ->fillForm(['location_id' => $this->centro->id, 'genetic_id' => $genetic->id, 'grams' => 50])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified(__(':genetic no tiene precio en :sede', ['genetic' => 'Sin Precio', 'sede' => 'Sede Centro']));

        // The stock IS recorded — the warning is a nudge, not a refusal.
        $this->assertSame(1, Batch::query()->withoutGlobalScopes()->count());
    }

    public function test_the_warning_links_to_where_the_price_is_set(): void
    {
        // The link target exists and is the genetic's own edit page — where GeneticPricesRelationManager lives.
        $genetic = $this->genetic('Enlazable');
        $this->assertStringContainsString(
            (string) $genetic->id,
            GeneticResource::getUrl('edit', ['record' => $genetic]),
        );
    }

    public function test_a_priced_genetic_gets_no_no_price_warning(): void
    {
        app(ActiveScope::class)->setLocation($this->centro->id);
        $genetic = $this->genetic('Con Precio', priceAt: $this->centro->id);

        Livewire::actingAs($this->owner)
            ->test(CreateBatch::class)
            ->fillForm(['location_id' => $this->centro->id, 'genetic_id' => $genetic->id, 'grams' => 50])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified(__('Lote añadido'));

        $this->assertSame(1, Batch::query()->withoutGlobalScopes()->count());
    }

    // --- The table filter --------------------------------------------------------

    public function test_the_sede_filter_narrows_the_list_to_one_sede(): void
    {
        $norte = $this->secondSede();
        app(ActiveScope::class)->setLocation(null);

        $atCentro = Batch::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->centro->id]);
        $atNorte = Batch::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $norte->id]);

        Livewire::actingAs($this->owner)
            ->test(ListBatches::class)
            ->filterTable('location_id', $this->centro->id)
            ->assertCanSeeTableRecords([$atCentro])
            ->assertCanNotSeeTableRecords([$atNorte]);
    }
}
