<?php

namespace Tests\Feature\Genetics;

use App\Enums\LocationKind;
use App\Enums\Role;
use App\Filament\Resources\Genetics\GeneticResource;
use App\Filament\Resources\Genetics\Pages\CreateGenetic;
use App\Filament\Resources\Genetics\Pages\EditGenetic;
use App\Filament\Resources\Genetics\Pages\ListGenetics;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Weight;
use Database\Seeders\RolePermissionSeeder;
use DomainException;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 308 — batches read "Bubble gum (eliminada)" beside an active *Bubble gum*: a strain was deleted while its
 * batches held stock, and a second one took its name. A strain with stock left anywhere (the store included) cannot be
 * deleted — the refusal says where the stock is — and no two strains in the club share a name, a deleted one included,
 * compared ignoring case, accents and spaces. Existing duplicates are left alone.
 */
class StrainGuardsTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $demo;

    private Location $norte;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->demo = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Demo']);
        $this->norte = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte', 'kind' => LocationKind::ALMACEN]);
        app(ActiveScope::class)->setLocation($this->demo->id);
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$this->demo->id, $this->norte->id]);
        $this->actingAs($owner);
    }

    private function strain(string $name): Genetic
    {
        return Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => $name]);
    }

    private function stock(Genetic $genetic, Location $at, int $cg): Batch
    {
        return Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $at->id, 'remaining_cg' => $cg]);
    }

    private function refusal(string $where): string
    {
        return __('No se puede borrar: quedan :where. Ponlos a cero con Merma o Recuento, o trasládalos, primero.', ['where' => $where]);
    }

    // --- 3–4. Deleting a strain with stock -------------------------------------------------------------------------------

    public function test_a_strain_with_stock_left_cannot_be_deleted_and_the_refusal_says_where(): void
    {
        $lemon = $this->strain('Lemon haze');
        $atDemo = $this->stock($lemon, $this->demo, 15000);
        $atStore = $this->stock($lemon, $this->norte, 70000);
        $this->stock($lemon, $this->demo, 0); // an empty batch is no obstacle

        $where = __(':amount en :sede', ['amount' => Weight::fromCentigrams(15000)->formatted(), 'sede' => 'Demo']).' '.__('y').' '
            .__(':amount en :sede', ['amount' => Weight::fromCentigrams(70000)->formatted(), 'sede' => 'Sede Norte']);
        Livewire::test(EditGenetic::class, ['record' => $lemon->getRouteKey()])
            ->callAction(DeleteAction::class)
            ->assertNotified($this->refusal($where));
        $this->assertNotSoftDeleted($lemon);

        // A forged path hits the same guard: the model refuses, whoever asks.
        try {
            $lemon->delete();
            $this->fail('a strain with stock was deleted');
        } catch (DomainException $e) {
            $this->assertSame($this->refusal($where), $e->getMessage());
        }
        $this->assertNotSoftDeleted($lemon);

        // Taken to zero → deletable, and restorable, as before.
        $atDemo->forceFill(['remaining_cg' => 0])->saveQuietly();
        $atStore->forceFill(['remaining_cg' => 0])->saveQuietly();
        Livewire::test(EditGenetic::class, ['record' => $lemon->getRouteKey()])->callAction(DeleteAction::class);
        $this->assertSoftDeleted($lemon);
        $lemon->fresh()->restore();
        $this->assertNotSoftDeleted($lemon);
    }

    public function test_a_bulk_delete_deletes_what_it_can_and_reports_the_rest(): void
    {
        $stocked = $this->strain('Stardog');
        $this->stock($stocked, $this->demo, 15000);
        $empty = $this->strain('Critical');

        Livewire::test(ListGenetics::class)->callTableBulkAction('delete', [$stocked, $empty]);

        $this->assertSoftDeleted($empty);
        $this->assertNotSoftDeleted($stocked);
        $notified = collect(session('filament.claimed_notifications') ?? session('filament.notifications'))->map(fn (array $n): string => ($n['title'] ?? '').' '.($n['body'] ?? ''))->implode(' ');
        $this->assertStringContainsString(trans_choice(':count genética borrada|:count genéticas borradas', 1, ['count' => 1]), $notified);
        $this->assertStringContainsString('Stardog', $notified);
        $this->assertStringContainsString(Weight::fromCentigrams(15000)->formatted(), $notified);
    }

    // --- 5. One name, one strain -----------------------------------------------------------------------------------------

    public function test_a_new_strain_cannot_take_an_existing_name_in_any_case_accent_or_spacing(): void
    {
        $this->strain('Lemon Haze');

        foreach (['lemon haze', '  LEMON  HAZE ', 'Lémon Haze'] as $typed) {
            Livewire::test(CreateGenetic::class)
                ->fillForm(['name' => $typed])
                ->goToNextWizardStep()
                ->assertHasFormErrors(['name' => __('Ya existe una genética con este nombre.')]);
        }

        Livewire::test(CreateGenetic::class)->fillForm(['name' => 'Lemon Kush'])->goToNextWizardStep()->assertHasNoFormErrors(['name']);
    }

    public function test_a_deleted_strain_with_the_name_is_offered_back(): void
    {
        $gum = $this->strain('Bubble gum');
        $gum->delete();

        Livewire::test(CreateGenetic::class)
            ->fillForm(['name' => 'bubble Gum'])
            ->goToNextWizardStep()
            ->assertHasFormErrors(['name' => __('Ya existe una genética borrada con este nombre. Restáurala en lugar de crear otra.')])
            ->assertSeeHtml(e(GeneticResource::getUrl('edit', ['record' => $gum])));
    }

    public function test_renaming_is_refused_onto_another_name_but_not_onto_its_own(): void
    {
        $this->strain('Amnesia Haze');
        $lemon = $this->strain('Lemon haze');

        Livewire::test(EditGenetic::class, ['record' => $lemon->getRouteKey()])
            ->fillForm(['name' => 'AMNESIA haze'])->call('save')
            ->assertHasFormErrors(['name' => __('Ya existe una genética con este nombre.')]);

        Livewire::test(EditGenetic::class, ['record' => $lemon->getRouteKey()])
            ->fillForm(['name' => 'Lemon Haze'])->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('Lemon Haze', $lemon->fresh()->name);
    }

    // --- 6. Existing duplicates are left alone -----------------------------------------------------------------------------

    public function test_existing_duplicates_are_untouched_and_still_editable(): void
    {
        $deleted = $this->strain('Bubble gum');
        $deleted->delete();
        $active = $this->strain('Bubble gum');

        Artisan::call('migrate', ['--force' => true]);

        $this->assertSame(['Bubble gum', 'Bubble gum'], Genetic::withTrashed()->orderBy('created_at')->pluck('name')->all());
        Livewire::test(EditGenetic::class, ['record' => $active->getRouteKey()])
            ->fillForm(['description' => 'La nueva'])->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('La nueva', $active->fresh()->description);
    }
}
