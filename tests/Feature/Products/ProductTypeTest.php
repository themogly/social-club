<?php

namespace Tests\Feature\Products;

use App\Enums\ProductType;
use App\Enums\UnitType;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Organisation;
use App\Support\ActiveScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The genetic product-type model: unit_type is derived + stored from product_type
 * (never user-entered), unit products must declare their per-unit gram content, and
 * the additive migration leaves every existing FLOWER/WEIGHT row untouched.
 */
class ProductTypeTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    public function test_the_migration_backfills_a_row_without_the_new_columns_to_flower_weight(): void
    {
        // A pre-existing genetic inserted without the new columns takes the FLOWER/WEIGHT default.
        $id = (string) Str::ulid();
        DB::table('genetics')->insert([
            'id' => $id, 'organisation_id' => $this->org->id, 'name' => 'Legacy',
            'thc_bp' => 1500, 'cbd_bp' => 200, 'published' => true, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = DB::table('genetics')->where('id', $id)->first();
        $this->assertSame('FLOWER', $row->product_type);
        $this->assertSame('WEIGHT', $row->unit_type);
        $this->assertNull($row->grams_per_unit_cg);
        $this->assertNull($row->concentrate_subtype);

        $genetic = Genetic::withoutGlobalScopes()->find($id);
        $this->assertNotNull($genetic);
        $this->assertFalse($genetic->isUnitType());
    }

    public function test_existing_weight_rows_keep_their_values_and_leave_the_unit_columns_null(): void
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'thc_bp' => 2000]);
        $price = GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id,
            'location_id' => $this->location->id, 'price_per_gram_cents' => 900,
        ]);
        $batch = Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id,
            'location_id' => $this->location->id, 'initial_cg' => 50000, 'remaining_cg' => 50000,
        ]);

        // The migration altered no existing value; the flower/weight shape is intact.
        $this->assertSame(ProductType::FLOWER, $genetic->fresh()->product_type);
        $this->assertSame(UnitType::WEIGHT, $genetic->fresh()->unit_type);
        $this->assertSame(2000, $genetic->fresh()->thc_bp);
        $this->assertSame(900, $price->fresh()->price_per_gram_cents);
        $this->assertNull($price->fresh()->price_per_unit_cents);
        $this->assertSame(50000, $batch->fresh()->remaining_cg->centigrams);
        $this->assertNull($batch->fresh()->remaining_units);
    }

    public function test_unit_type_derives_and_stores_on_create_and_on_product_type_change(): void
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        $this->assertSame(UnitType::WEIGHT, $genetic->unit_type);
        $this->assertSame('WEIGHT', DB::table('genetics')->where('id', $genetic->id)->value('unit_type'));

        // Switching to a per-unit product re-derives + stores UNIT.
        $genetic->update(['product_type' => ProductType::PREROLL, 'grams_per_unit_cg' => 70]);
        $this->assertSame(UnitType::UNIT, $genetic->fresh()->unit_type);
        $this->assertSame('UNIT', DB::table('genetics')->where('id', $genetic->id)->value('unit_type'));
    }

    public function test_unit_type_is_not_settable_via_mass_assignment(): void
    {
        // A FLOWER genetic cannot be forced to UNIT by mass-assigning unit_type.
        $genetic = Genetic::create([
            'organisation_id' => $this->org->id, 'name' => 'Tries to cheat',
            'product_type' => ProductType::FLOWER, 'unit_type' => 'UNIT',
        ]);

        $this->assertSame(UnitType::WEIGHT, $genetic->fresh()->unit_type);
    }

    public function test_creating_a_preroll_or_edible_without_grams_per_unit_is_rejected(): void
    {
        foreach ([ProductType::PREROLL, ProductType::EDIBLE] as $type) {
            try {
                Genetic::create([
                    'organisation_id' => $this->org->id, 'name' => 'No gpu '.$type->value,
                    'product_type' => $type,
                ]);
                $this->fail("A {$type->value} without grams_per_unit_cg must be rejected.");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_switching_away_from_a_unit_type_clears_the_unit_only_fields(): void
    {
        $genetic = Genetic::factory()->edible(100, 10)->create(['organisation_id' => $this->org->id]);
        $this->assertSame(100, $genetic->grams_per_unit_cg);
        $this->assertSame(10, $genetic->thc_mg_per_unit);

        $genetic->update(['product_type' => ProductType::FLOWER]);
        $fresh = $genetic->fresh();
        $this->assertSame(UnitType::WEIGHT, $fresh->unit_type);
        $this->assertNull($fresh->grams_per_unit_cg);
        $this->assertNull($fresh->thc_mg_per_unit);
    }
}
