<?php

namespace Tests\Feature\Stock;

use App\Enums\ProductType;
use App\Enums\Role;
use App\Enums\StrainType;
use App\Filament\Resources\Batches\Pages\CreateBatch;
use App\Filament\Resources\Batches\Pages\EditBatch;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 323 — Ben: "When you select a genetic you should first select the type, as it's hard to know what you're adding
 * to." *Crear lote* asks the product type first (a filter, never stored), then offers only active strains of that type,
 * each labelled "Name · Variety · THC x%". 320's `?genetic=` hand-off fills both.
 */
class TypeFirstStrainPickerTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private Genetic $caliFlor;

    private Genetic $caliHash;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        app(ActiveScope::class)->setLocation($this->centro->id);
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$this->centro->id]);
        $this->actingAs($owner);

        $this->caliFlor = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Cali', 'product_type' => ProductType::FLOWER, 'strain_type' => StrainType::HYBRID, 'thc_bp' => 2200, 'active' => true]);
        $this->caliHash = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Cali Hash', 'product_type' => ProductType::HASH, 'strain_type' => null, 'thc_bp' => null, 'active' => true]);
    }

    /** @return array<string, string> the strain options the form offers right now */
    private static function strainOptions($page): array
    {
        return self::strainField($page)->getOptions();
    }

    private static function strainField($page): Select
    {
        return $page->instance()->getSchema('form')->getComponent(fn ($c): bool => $c instanceof Select && $c->getName() === 'genetic_id', withHidden: true);
    }

    // --- 1. Type first; only that type; a forged id of the wrong type is refused -------------------------------------------

    public function test_the_strain_waits_for_the_type_and_then_offers_only_active_strains_of_it(): void
    {
        Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Hash Retirado', 'product_type' => ProductType::HASH, 'active' => false]);

        $page = Livewire::test(CreateBatch::class)->assertFormFieldExists('product_type')->assertFormFieldIsDisabled('genetic_id');

        $page->fillForm(['product_type' => ProductType::HASH->value])->assertFormFieldIsEnabled('genetic_id');
        $this->assertSame([$this->caliHash->id], array_keys(self::strainOptions($page)));

        $page->fillForm(['location_id' => $this->centro->id, 'product_type' => ProductType::HASH->value, 'genetic_id' => $this->caliFlor->id, 'grams' => '50', 'sale_price_eur' => '8'])
            ->call('create')->assertHasFormErrors(['genetic_id']);
        $this->assertSame(0, Batch::query()->withoutGlobalScopes()->count());
    }

    // --- 2. Changing the type clears a strain that no longer fits ----------------------------------------------------------

    public function test_changing_the_type_clears_a_strain_of_the_old_type(): void
    {
        Livewire::test(CreateBatch::class)
            ->fillForm(['product_type' => ProductType::FLOWER->value])
            ->fillForm(['genetic_id' => $this->caliFlor->id])
            ->fillForm(['product_type' => ProductType::HASH->value])
            ->assertSchemaStateSet(['genetic_id' => null]);
    }

    // --- 3. A little more than the name; search by name still works -------------------------------------------------------

    public function test_options_read_name_variety_and_thc_and_search_by_name(): void
    {
        $page = Livewire::test(CreateBatch::class)->fillForm(['product_type' => ProductType::FLOWER->value]);
        $this->assertSame(['Cali · '.StrainType::HYBRID->label().' · THC 22%'], array_values(self::strainOptions($page)));

        $hash = Livewire::test(CreateBatch::class)->fillForm(['product_type' => ProductType::HASH->value]);
        $this->assertSame(['Cali Hash'], array_values(self::strainOptions($hash)), 'an empty variety or THC is left out');

        $field = self::strainField($page);
        $this->assertSame([$this->caliFlor->id], array_keys($field->getSearchResults('cal')));
    }

    // --- 4. 320's hand-off fills the type too --------------------------------------------------------------------------------

    public function test_opening_with_a_strain_fills_its_type_and_keeps_the_strain(): void
    {
        Livewire::withQueryParams(['genetic' => $this->caliHash->id])->test(CreateBatch::class)
            ->assertSchemaStateSet(['product_type' => ProductType::HASH->value, 'genetic_id' => $this->caliHash->id])
            ->assertFormFieldIsEnabled('genetic_id');
    }

    // --- 5. The batch saves exactly as before (a pin) --------------------------------------------------------------------------

    public function test_the_batch_saves_as_before_and_no_type_is_stored_on_it(): void
    {
        Livewire::test(CreateBatch::class)
            ->fillForm(['location_id' => $this->centro->id, 'product_type' => ProductType::FLOWER->value, 'genetic_id' => $this->caliFlor->id, 'grams' => '50', 'sale_price_eur' => '8'])
            ->call('create')->assertHasNoFormErrors();

        $batch = Batch::query()->withoutGlobalScopes()->sole();
        $this->assertSame($this->caliFlor->id, $batch->genetic_id);
        $this->assertSame(5000, $batch->getRawOriginal('remaining_cg'));
        $this->assertFalse(Schema::hasColumn('batches', 'product_type'));
        $this->assertArrayNotHasKey('product_type', $batch->getAttributes());

        Livewire::test(EditBatch::class, ['record' => $batch->getRouteKey()])->assertFormFieldIsHidden('product_type');
    }
}
