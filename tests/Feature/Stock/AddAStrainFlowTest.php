<?php

namespace Tests\Feature\Stock;

use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Filament\Resources\Genetics\Pages\CreateGenetic;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AddAStrainFlowTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $a;

    private Location $b;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->a = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Demo A']);
        $this->b = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Demo B']);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        Filament::setCurrentPanel('admin');
        app(ActiveScope::class)->setLocation($this->a->id);
    }

    public function test_the_flow_yields_a_sellable_strain_at_the_chosen_sede(): void
    {
        Livewire::actingAs($this->owner)->test(CreateGenetic::class)
            ->fillForm([
                'name' => 'Amnesia Haze',
                'product_type' => 'FLOWER',
                'grams' => 250,
                'cost_per_gram_eur' => 4,
                'location_id' => $this->a->id,
                'price_per_gram_eur' => 8,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $genetic = Genetic::query()->withoutGlobalScopes()->where('name', 'Amnesia Haze')->sole();
        $this->assertTrue($genetic->active);

        // A batch at A, with the opening INTAKE movement (through IntakeBatch, not a hand-built row).
        $batch = Batch::query()->withoutGlobalScopes()->where('genetic_id', $genetic->id)->sole();
        $this->assertSame($this->a->id, $batch->location_id);
        $this->assertSame(25000, $batch->getRawOriginal('remaining_cg')); // 250 g
        $this->assertSame(1, StockMovement::query()->withoutGlobalScopes()
            ->where('stockable_type', Batch::class)->where('stockable_id', $batch->id)
            ->where('type', StockMovementType::INTAKE->value)->count());

        // A base price at A (€8/g = 800 c).
        $price = GeneticPrice::query()->withoutGlobalScopes()
            ->where('genetic_id', $genetic->id)->where('location_id', $this->a->id)->whereNull('tier_id')->sole();
        $this->assertSame(800, (int) $price->getRawOriginal('price_per_gram_cents'));

        // Sellable at A immediately, and NOT at B.
        $this->assertTrue($genetic->fresh()->hasActivePriceAt($this->a->id) && $genetic->fresh()->hasStockAt($this->a->id));
        $this->assertSame(1, Genetic::query()->withoutGlobalScopes()->sellableAt($this->a->id)->where('genetics.id', $genetic->id)->count());
        $this->assertSame(0, Genetic::query()->withoutGlobalScopes()->sellableAt($this->b->id)->where('genetics.id', $genetic->id)->count());
    }

    /** All or nothing: a domain failure in a later write rolls back the genetic and the batch (atomicity). */
    public function test_a_failed_write_rolls_back_the_whole_strain(): void
    {
        // Force the batch write to fail: the ceiling set to BLOCK, and an intake far over it.
        $matrix = Settings::get('enforcement', Settings::DEFAULTS['enforcement']);
        $matrix['stock']['ceiling'] = 'BLOCK';
        Settings::set('enforcement', $matrix);

        try {
            Livewire::actingAs($this->owner)->test(CreateGenetic::class)
                ->fillForm([
                    'name' => 'Rollback Kush', 'product_type' => 'FLOWER',
                    'grams' => 1000000, 'cost_per_gram_eur' => 4,
                    'location_id' => $this->a->id, 'price_per_gram_eur' => 8,
                ])
                ->call('create');
        } catch (\Throwable) {
            // the transaction threw — the point is that NOTHING persisted.
        }

        $this->assertSame(0, Genetic::query()->withoutGlobalScopes()->count(), 'the genetic survived a failed batch write');
        $this->assertSame(0, Batch::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, GeneticPrice::query()->withoutGlobalScopes()->count());
    }

    /** The photo step opens the tablet's rear camera directly (capture=environment), not a file browser. */
    public function test_the_photo_step_opens_the_camera(): void
    {
        $src = (string) file_get_contents(app_path('Filament/Resources/Genetics/Pages/CreateGenetic.php'));

        $this->assertMatchesRegularExpression(
            "/make\('images'\)[\s\S]*?'capture'\s*=>\s*'environment'/",
            $src,
            'the photo step must carry the rear-camera hint',
        );
    }

    /** 238's sede rule, re-asserted here: blank and required in the "all sedes" rollup. */
    public function test_the_sede_step_is_blank_and_required_in_the_rollup(): void
    {
        app(ActiveScope::class)->setLocation(null); // the rollup — no scope to inherit

        Livewire::actingAs($this->owner)->test(CreateGenetic::class)
            ->assertFormSet(['location_id' => null])
            ->fillForm([
                'name' => 'Sin Sede', 'product_type' => 'FLOWER',
                'grams' => 100, 'cost_per_gram_eur' => 4, 'price_per_gram_eur' => 8,
            ])
            ->call('create')
            ->assertHasFormErrors(['location_id']);

        $this->assertSame(0, Genetic::query()->withoutGlobalScopes()->count());
    }
}
